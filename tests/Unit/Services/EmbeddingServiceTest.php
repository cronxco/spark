<?php

namespace Tests\Unit\Services;

use App\Services\EmbeddingService;
use Exception;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-api-key',
            'services.openai.models.embedding' => 'text-embedding-3-small',
        ]);
    }

    /**
     * @test
     */
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

        $result = (new EmbeddingService)->embed('searchable text', useCache: false);

        $this->assertCount(1536, $result);
        $this->assertSame(0.25, $result[0]);
        $this->assertFalse(EmbeddingService::isZeroVector($result));
    }

    /**
     * @test
     */
    public function throws_provider_failure_instead_of_returning_zero_vector(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['error' => 'provider unavailable'], 500),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('provider unavailable');

        (new EmbeddingService)->embed('searchable text', useCache: false);
    }

    /**
     * @test
     */
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

        (new EmbeddingService)->embed('searchable text', useCache: false);
    }

    private function validEmbedding(): array
    {
        $embedding = array_fill(0, 1536, 0.0);
        $embedding[0] = 0.25;

        return $embedding;
    }
}
