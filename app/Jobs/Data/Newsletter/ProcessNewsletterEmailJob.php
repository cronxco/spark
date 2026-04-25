<?php

namespace App\Jobs\Data\Newsletter;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

class ProcessNewsletterEmailJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for email processing + AI

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public ?string $s3ObjectKey = null,
        public ?string $rawEmailContent = null
    ) {}

    public function handle(): void
    {
        Log::info('Newsletter: Processing newsletter email', [
            'integration_id' => $this->integration->id,
            's3_object_key' => $this->s3ObjectKey,
            'has_raw_content' => ! empty($this->rawEmailContent),
        ]);

        try {
            // Get email content - either from raw content or S3
            if (! empty($this->rawEmailContent)) {
                $emailContent = $this->rawEmailContent;
            } elseif (! empty($this->s3ObjectKey)) {
                $emailContent = $this->downloadEmailFromS3($this->s3ObjectKey);
            } else {
                throw new Exception('No email content or S3 key provided');
            }

            // Parse email to extract metadata and HTML
            $parsedEmail = $this->parseEmail($emailContent);

            // Identify publication from sender
            $publication = $this->getOrCreatePublication($parsedEmail);

            // Create newsletter event
            $event = $this->createNewsletterEvent($publication, $parsedEmail);

            // Dispatch content extraction job
            ExtractNewsletterContentJob::dispatch(
                $this->integration,
                $event,
                $publication,
                $parsedEmail['text_html'] ?: $parsedEmail['text_plain']
            );

            Log::info('Newsletter: Successfully processed newsletter email', [
                'integration_id' => $this->integration->id,
                's3_object_key' => $this->s3ObjectKey,
                'event_id' => $event->id,
                'publication_id' => $publication->id,
            ]);
        } catch (Exception $e) {
            Log::error('Newsletter: Failed to process email', [
                'integration_id' => $this->integration->id,
                's3_object_key' => $this->s3ObjectKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        $contentHash = $this->s3ObjectKey
            ? md5($this->s3ObjectKey)
            : md5($this->rawEmailContent ?? '');

        return 'process_newsletter_email_' . $this->integration->id . '_' . $contentHash;
    }

    /**
     * Download email file from S3
     */
    private function downloadEmailFromS3(string $objectKey): string
    {
        $disk = Storage::disk('s3-newsletters');

        if (! $disk->exists($objectKey)) {
            throw new Exception("Email file not found in S3: {$objectKey}");
        }

        $content = $disk->get($objectKey);

        Log::info('Newsletter: Downloaded email from S3', [
            's3_object_key' => $objectKey,
            'size_bytes' => strlen($content),
        ]);

        return $content;
    }

    /**
     * Parse email content to extract metadata, HTML, and text
     */
    private function parseEmail(string $emailContent): array
    {
        try {
            // Use MailMimeParser to parse the email
            $parser = new MailMimeParser;
            $message = $parser->parse($emailContent, false);

            // Extract basic fields
            $subject = $message->getHeaderValue('subject') ?: 'No Subject';
            $date = $message->getHeaderValue('date') ?: now()->toRfc2822String();
            $messageId = $message->getHeaderValue('message-id') ?: '';

            // Extract sender information properly
            $fromEmail = '';
            $fromName = '';

            try {
                $fromHeader = $message->getHeader('from');
                // Check if this is an AddressHeader with getAddresses method
                if ($fromHeader instanceof AddressHeader) {
                    $fromAddresses = $fromHeader->getAddresses();
                    if (count($fromAddresses) > 0) {
                        $fromAddress = $fromAddresses[0];
                        $fromEmail = $fromAddress->getEmail() ?: '';
                        $fromName = $fromAddress->getName() ?: '';
                    }
                }
            } catch (Exception $e) {
                // Fallback to simple header value parsing
                $fromValue = $message->getHeaderValue('from') ?: '';
                preg_match('/<([^>]+)>/', $fromValue, $emailMatches);
                $fromEmail = $emailMatches[1] ?? $fromValue;
                $fromName = preg_replace('/<[^>]+>/', '', $fromValue);
                $fromName = trim($fromName, " \t\n\r\0\x0B\"'");
            }

            // Extract content
            $textPlain = $message->getTextContent() ?: '';
            $textHtml = $message->getHtmlContent() ?: '';

            Log::info('Newsletter: Parsed email', [
                'subject' => $subject,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'message_id' => $messageId,
                'has_html' => ! empty($textHtml),
                'has_plain' => ! empty($textPlain),
            ]);

            return [
                'subject' => $subject,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'date' => $date,
                'message_id' => $messageId,
                'text_plain' => $textPlain,
                'text_html' => $textHtml,
            ];
        } catch (Exception $e) {
            Log::error('Newsletter: Email parsing failed', [
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to parse email: ' . $e->getMessage());
        }
    }

    /**
     * Get or create publication EventObject from email sender
     */
    private function getOrCreatePublication(array $parsedEmail): EventObject
    {
        // Extract sender information from parsed email
        $senderEmail = $parsedEmail['from_email'];
        $senderName = $parsedEmail['from_name'];

        // If no name was provided, use email prefix as fallback
        if (empty($senderName)) {
            $senderName = explode('@', $senderEmail)[0];
            $senderName = ucwords(str_replace(['.', '_', '-'], ' ', $senderName));
        }

        // Extract domain from email
        $senderDomain = '';
        if (str_contains($senderEmail, '@')) {
            $senderDomain = explode('@', $senderEmail)[1];
        }

        // Fetch existing publication to get current post count
        $existingPublication = EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'publication')
            ->where('type', 'newsletter_publication')
            ->where('title', $senderName)
            ->first();

        // Compute new post count
        $currentMetadata = $existingPublication?->metadata ?? [];
        $postCount = ($currentMetadata['post_count'] ?? 0) + 1;

        // Create or update publication EventObject
        $publication = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'publication',
                'type' => 'newsletter_publication',
                'title' => $senderName,
            ],
            [
                'time' => now(),
                'metadata' => [
                    'sender_email' => $senderEmail,
                    'sender_domain' => $senderDomain,
                    'sender_name' => $senderName,
                    'normalized_name' => strtolower($senderName),
                    'last_post_at' => now()->toIso8601String(),
                    'post_count' => $postCount,
                ],
            ]
        );

        Log::info('Newsletter: Publication identified', [
            'publication_id' => $publication->id,
            'title' => $publication->title,
            'sender_email' => $senderEmail,
            'sender_domain' => $senderDomain,
        ]);

        return $publication;
    }

    /**
     * Create newsletter event
     */
    private function createNewsletterEvent(EventObject $publication, array $parsedEmail): Event
    {
        // Create user actor EventObject
        $actor = EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'newsletter_user',
                'title' => 'Me',
            ],
            [
                'time' => now(),
                'metadata' => [],
            ]
        );

        // Parse email received date
        try {
            $receivedTime = Carbon::parse($parsedEmail['date']);
        } catch (Exception $e) {
            Log::warning('Newsletter: Failed to parse email date, using current time', [
                'original_date' => $parsedEmail['date'],
                'error' => $e->getMessage(),
            ]);
            $receivedTime = now();
        }
        // Create newsletter event
        $event = Event::create([
            'source_id' => $parsedEmail['message_id'] ?: 'newsletter_' . Str::uuid(),
            'time' => $receivedTime,
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'actor_metadata' => [],
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'target_id' => $publication->id,
            'target_metadata' => [],
            'value' => null,
            'value_multiplier' => null,
            'value_unit' => null,
            'event_metadata' => [
                'email_subject' => $parsedEmail['subject'],
                'email_from' => $parsedEmail['from_email'],
                'email_from_name' => $parsedEmail['from_name'],
                'email_received_at' => $receivedTime->toIso8601String(),
                'email_message_id' => $parsedEmail['message_id'],
                'raw_html' => $parsedEmail['text_html'],
                's3_object_key' => $this->s3ObjectKey,
            ],
        ]);

        Log::info('Newsletter: Created newsletter event', [
            'event_id' => $event->id,
            'publication_id' => $publication->id,
            'subject' => $parsedEmail['subject'],
        ]);

        return $event;
    }
}
