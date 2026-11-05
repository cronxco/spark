<?php

namespace App\Services\Capture;

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Fetch\BookmarkUrlService;
use App\Services\Media\MediaDownloadHelper;
use finfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One way in for things shared into Spark (decision C-5). Captures are
 * ordinary events on the user's Manual Log integration, so there is no new
 * table: a URL becomes a bookmark as before, free text goes to the user's
 * Inbox object for later sorting, and an image gets an object of its own.
 * A retried capture with the same idempotency key returns the first receipt.
 */
class CaptureService
{
    public const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/heic' => 'heic', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function __construct(
        protected BookmarkUrlService $bookmarks,
        protected MediaDownloadHelper $media,
    ) {}

    /**
     * @param  array{kind: string, idempotency_key: string, url?: string, text?: string, title?: string|null, image?: string}  $input
     * @return array{receipt: array<string, mixed>, created: bool}
     */
    public function capture(User $user, array $input): array
    {
        if ($input['kind'] === 'url') {
            return $this->captureUrl($user, $input);
        }

        $integration = ManualLogPlugin::resolveIntegration($user->id);

        return DB::transaction(function () use ($user, $input, $integration): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["capture:{$user->id}:{$input['idempotency_key']}"]);

            $existing = Event::query()
                ->where('integration_id', $integration->id)
                ->whereIn('action', ManualLogPlugin::CAPTURE_ACTIONS)
                ->where('event_metadata->idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existing !== null) {
                return ['receipt' => $this->eventReceipt($existing), 'created' => false];
            }

            $event = $input['kind'] === 'text'
                ? $this->captureText($user, $integration->id, $input)
                : $this->captureImage($user, $integration->id, $input);

            return ['receipt' => $this->eventReceipt($event), 'created' => true];
        });
    }

    /**
     * The user's Inbox, where captured free text waits to be sorted.
     */
    public function inbox(User $user): EventObject
    {
        return EventObject::firstOrCreate(
            ['user_id' => $user->id, 'concept' => 'inbox', 'type' => 'capture_inbox', 'title' => 'Inbox'],
            ['time' => now()],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{receipt: array<string, mixed>, created: bool}
     */
    private function captureUrl(User $user, array $input): array
    {
        $result = $this->bookmarks->bookmark($user, $input['url']);

        return [
            'receipt' => [
                'id' => (string) $result['bookmark']->id,
                'kind' => 'url',
                'status' => 'accepted',
                'idempotency_key' => $input['idempotency_key'],
                'destination' => ['type' => 'object', 'id' => (string) $result['bookmark']->id],
                'state' => $result['state'],
            ],
            'created' => $result['created'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function captureText(User $user, string $integrationId, array $input): Event
    {
        $text = trim((string) $input['text']);

        return $this->createEvent($user, $integrationId, 'captured_text', $this->inbox($user), [
            'idempotency_key' => $input['idempotency_key'],
            'title' => $input['title'] ?? Str::limit(Str::squish($text), 80),
            'text' => $text,
            'triage' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function captureImage(User $user, string $integrationId, array $input): Event
    {
        $content = base64_decode((string) $input['image'], strict: true);
        if ($content === false || $content === '') {
            throw new InvalidArgumentException('The image could not be read.');
        }

        if (strlen($content) > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('The image is too large.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $extension = self::IMAGE_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new InvalidArgumentException('Only JPEG, PNG, HEIC, GIF or WebP images can be captured.');
        }

        $title = $input['title'] ?? 'Image captured ' . now()->timezone($user->getTimezone())->format('j M Y, H:i');
        $image = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'capture',
            'type' => 'captured_image',
            'title' => $title,
            'time' => now(),
            'metadata' => ['idempotency_key' => $input['idempotency_key']],
        ]);

        if ($this->media->attachMediaFromBase64((string) $input['image'], $image, "capture-{$image->id}.{$extension}", 'downloaded_images') === null) {
            throw new InvalidArgumentException('The image could not be stored.');
        }

        return $this->createEvent($user, $integrationId, 'captured_image', $image, [
            'idempotency_key' => $input['idempotency_key'],
            'title' => $title,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createEvent(User $user, string $integrationId, string $action, EventObject $target, array $metadata): Event
    {
        $actor = EventObject::firstOrCreate(
            ['user_id' => $user->id, 'concept' => 'user', 'type' => 'manual_log_user', 'title' => $user->name ?? 'User'],
            ['time' => now()],
        );

        return Event::create([
            'source_id' => 'capture_' . $metadata['idempotency_key'],
            'time' => now(),
            'integration_id' => $integrationId,
            'actor_id' => $actor->id,
            'service' => ManualLogPlugin::getIdentifier(),
            'domain' => 'knowledge',
            'action' => $action,
            'value_multiplier' => 1,
            'target_id' => $target->id,
            'event_metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventReceipt(Event $event): array
    {
        return [
            'id' => (string) $event->id,
            'kind' => $event->action === 'captured_text' ? 'text' : 'image',
            'status' => 'accepted',
            'idempotency_key' => $event->event_metadata['idempotency_key'] ?? null,
            'destination' => ['type' => 'event', 'id' => (string) $event->id, 'object_id' => (string) $event->target_id],
            'created_at' => $event->created_at?->toJSON(),
        ];
    }
}
