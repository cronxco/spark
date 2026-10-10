<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Newsletter\NewsletterPlugin;
use App\Jobs\Data\Newsletter\ProcessNewsletterEmailJob;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NewsletterSnsAuthenticationTest extends TestCase
{
    private const TOPIC = 'arn:aws:sns:eu-west-1:123456789012:newsletters';

    public static function notificationSignatures(): array
    {
        return [
            'SHA1 without subject' => ['1', null],
            'SHA256 without subject' => ['2', null],
            'SHA1 with subject' => ['1', 'Newsletter subject'],
            'SHA256 with subject' => ['2', 'Newsletter subject'],
        ];
    }

    #[Test]
    public function rejects_an_unconfigured_or_foreign_topic_before_any_http_request(): void
    {
        Http::fake();
        Queue::fake();
        config(['services.newsletter.sns_topic_arn' => null]);

        $this->assertRejected(['Type' => 'Notification', 'TopicArn' => self::TOPIC], 403);
        config(['services.newsletter.sns_topic_arn' => self::TOPIC]);
        $this->assertRejected(['Type' => 'Notification', 'TopicArn' => self::TOPIC . '-other'], 403);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function rejects_unsigned_subscription_confirmation_without_fetching_its_url(): void
    {
        Http::fake();
        Queue::fake();
        config(['services.newsletter.sns_topic_arn' => self::TOPIC]);

        $this->assertRejected([
            'Type' => 'SubscriptionConfirmation',
            'TopicArn' => self::TOPIC,
            'SigningCertURL' => 'http://127.0.0.1/private.pem',
            'SubscribeURL' => 'http://127.0.0.1/admin',
        ], 403);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    #[DataProvider('notificationSignatures')]
    public function validates_a_real_signature_before_dispatching_a_notification(string $version, ?string $subject): void
    {
        Queue::fake();
        config(['services.newsletter.sns_topic_arn' => self::TOPIC]);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'SNS test'], $key), null, $key, 1);
        openssl_x509_export($certificate, $pem);
        $payload = [
            'Type' => 'Notification',
            'MessageId' => 'test-message',
            'TopicArn' => self::TOPIC,
            'Message' => json_encode(['content' => 'From: sender@example.com\\r\\n\\r\\nTest issue']),
            'Timestamp' => '2026-10-10T12:00:00Z',
            'SignatureVersion' => $version,
            'SigningCertURL' => 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem',
        ];
        if ($subject !== null) {
            $payload['Subject'] = $subject;
        }
        $stringToSign = '';
        $fields = ['Message', 'MessageId'];
        if ($subject !== null) {
            $fields[] = 'Subject';
        }
        $fields = array_merge($fields, ['Timestamp', 'TopicArn', 'Type']);
        foreach ($fields as $field) {
            $stringToSign .= $field . "\n" . $payload[$field] . "\n";
        }
        openssl_sign($stringToSign, $signature, $key, $version === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
        $payload['Signature'] = base64_encode($signature);
        Http::fake([$payload['SigningCertURL'] => Http::response($pem)]);

        (new NewsletterPlugin)->handleWebhook($this->request($payload), $this->integration());

        Queue::assertPushed(ProcessNewsletterEmailJob::class);
        $payload['Message'] = json_encode(['content' => 'tampered']);
        $this->assertRejected($payload, 403);
        Queue::assertPushed(ProcessNewsletterEmailJob::class, 1);
    }

    #[Test]
    public function verifies_subscription_signature_before_confirming(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.newsletter.sns_topic_arn' => self::TOPIC]);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'SNS test'], $key), null, $key, 1);
        openssl_x509_export($certificate, $pem);
        $payload = [
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'test-subscription',
            'TopicArn' => self::TOPIC,
            'Message' => 'Confirm this subscription',
            'SubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&Token=test-token',
            'Token' => 'test-token',
            'Timestamp' => '2026-10-10T12:00:00Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem',
        ];
        $stringToSign = '';
        foreach (['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'] as $field) {
            $stringToSign .= $field . "\n" . $payload[$field] . "\n";
        }
        openssl_sign($stringToSign, $signature, $key, OPENSSL_ALGO_SHA256);
        $payload['Signature'] = base64_encode($signature);
        Http::fake([
            $payload['SigningCertURL'] => Http::response($pem),
            $payload['SubscribeURL'] => Http::response('confirmed'),
        ]);

        (new NewsletterPlugin)->handleWebhook($this->request($payload), $this->integration());
        Http::assertSent(fn ($request) => $request->url() === $payload['SubscribeURL']);
        Http::assertSentCount(2);

        $payload['Token'] = 'tampered';
        $this->assertRejected($payload, 403);
        Http::assertSentCount(3);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function preserves_direct_ses_only_with_the_matching_webhook_secret(): void
    {
        Queue::fake();
        Http::fake();
        $payload = ['content' => 'test issue', 'receipt' => ['action' => ['type' => 'SNS']]];
        $this->assertRejected($payload, 401, 'wrong-secret');
        Queue::assertNothingPushed();

        (new NewsletterPlugin)->handleWebhook($this->request($payload), $this->integration());

        Queue::assertPushed(ProcessNewsletterEmailJob::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function redacts_headers_containing_the_secret_webhook_path(): void
    {
        $plugin = new class extends NewsletterPlugin
        {
            public function headersForTest(array $headers): array
            {
                return $this->sanitizeHeaders($headers);
            }
        };
        $result = $plugin->headersForTest(['X-Original-URL' => ['/webhook/secret'], 'x-forwarded-uri' => ['/webhook/secret']]);
        $this->assertSame(['[REDACTED]'], $result['X-Original-URL']);
        $this->assertSame(['[REDACTED]'], $result['x-forwarded-uri']);
    }

    private function assertRejected(array $payload, int $status, string $secret = 'test-secret'): void
    {
        try {
            (new NewsletterPlugin)->handleWebhook($this->request($payload, $secret), $this->integration());
            $this->fail('Expected webhook rejection');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function request(array $payload, string $secret = 'test-secret'): Request
    {
        $request = Request::create('/webhook/' . $secret, 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($payload));
        $route = new Route('POST', 'webhook/{secret}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function integration(): Integration
    {
        $integration = new Integration;
        $integration->id = 'test-newsletter';
        $integration->account_id = 'test-secret';

        return $integration;
    }
}
