<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionContext;
use Tests\TestCase;

class AppServiceProviderSentryTracingTest extends TestCase
{
    #[Test]
    public function with_sentry_tracing_macro_does_not_throw_when_a_span_is_active(): void
    {
        Http::fake([
            'example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('test.transaction');
        $txContext->setOp('test');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            $response = Http::withSentryTracing()->get('https://example.test/foo');
        } finally {
            $transaction->finish();
            $hub->setSpan(null);
        }

        $this->assertTrue($response->ok());
        $this->assertSame(['ok' => true], $response->json());
    }
}
