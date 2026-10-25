<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\Mobile\EventLookup;
use App\Support\TagName;
use Spatie\Tags\Tag;

class AddEventTag implements ActionHandler, Reversible
{
    public function __construct(private EventLookup $events) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        $event = $this->events->find($user, $target['event_id']) ?? throw ActionFailed::notFound('Event');

        [$name, $type] = TagName::normalise($target['name'], null);
        if ($name === '') {
            throw new ActionFailed('Tag name is empty.', 'invalid');
        }

        $tag = Tag::findOrCreate($name, $type);
        $alreadyTagged = $event->tags()->where('tags.id', $tag->id)->exists();

        if (! $alreadyTagged) {
            $event->attachTags([$tag]);
            $event->touch();
        }

        $result = ['event_id' => $event->id, 'tag_id' => $tag->id];

        // Undo only what this run changed.
        return ActionOutcome::succeeded("Tagged {$name}", $result, $alreadyTagged ? null : $result);
    }

    public function undo(User $user, array $undo): void
    {
        $event = $this->events->find($user, $undo['event_id']);
        $tag = Tag::find($undo['tag_id']);

        if ($event && $tag) {
            $event->detachTags([$tag]);
            $event->touch();
        }
    }
}
