<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\EmbeddingClient;
use Exception;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmbeddingClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-api-key',
            'services.openai.models.embedding' => 'text-embedding-3-small',
        ]);
    }

    #[Test]
    public function returns_valid_embedding_from_provider(): void
    {
        $embedding = $this->validEmbedding();

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => $embedding],
                ],
            ]),
        ]);

        $result = (new EmbeddingClient)->embed('searchable text', useCache: false);

        $this->assertCount(1536, $result);
        $this->assertSame(0.25, $result[0]);
        $this->assertFalse(EmbeddingClient::isZeroVector($result));
    }

    #[Test]
    public function throws_provider_failure_instead_of_returning_zero_vector(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['error' => 'provider unavailable'], 500),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('provider unavailable');

        (new EmbeddingClient)->embed('searchable text', useCache: false);
    }

    #[Test]
    public function rejects_zero_vector_provider_response(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, 1536, 0.0)],
                ],
            ]),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('zero vector');

        (new EmbeddingClient)->embed('searchable text', useCache: false);
    }

    private function validEmbedding(): array
    {
        $embedding = array_fill(0, 1536, 0.0);
        $embedding[0] = 0.25;

        return $embedding;
    }

    #[Test]
    public function it_opens_a_gen_ai_span_so_the_highest_volume_ai_call_is_traced(): void
    {
        $embedding = $this->validEmbedding();

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => $embedding]],
                'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
            ]),
        ]);

        $transaction = \Sentry\startTransaction(
            new \Sentry\Tracing\TransactionContext('embedding-test')
        );
        \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);

        (new EmbeddingClient)->embed('some text', useCache: false);

        $spans = $transaction->getSpanRecorder()?->getSpans() ?? [];
        $genAiSpans = array_values(array_filter(
            $spans,
            fn ($span) => $span->getOp() === 'gen_ai.request'
        ));

        $this->assertNotEmpty($genAiSpans, 'Embedding call produced no gen_ai.request span');
        $this->assertSame(
            7,
            $genAiSpans[0]->getData()['gen_ai.usage.input_tokens'] ?? null,
            'Embedding span did not record token usage'
        );
    }
}
