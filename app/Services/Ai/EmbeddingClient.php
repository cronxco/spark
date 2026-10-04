<?php

namespace App\Services\Ai;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmbeddingClient
{
    private ?string $apiKey;

    private ?string $organization;

    private string $model;

    private string $apiUrl = 'https://api.openai.com/v1/embeddings';

    private int $dimensions = 1536;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->organization = config('services.openai.organization');
        $this->model = AiModel::Embedding->model();

        if (empty($this->apiKey)) {
            throw new Exception('OpenAI API key is not configured');
        }
    }

    /**
     * Format embedding vector as PostgreSQL vector string
     */
    public static function formatForPostgres(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }

    /**
     * Parse PostgreSQL vector string to array
     */
    public static function parseFromPostgres(?string $vector): ?array
    {
        if ($vector === null) {
            return null;
        }

        // Remove brackets and split by comma
        $vector = trim($vector, '[]');
        $values = explode(',', $vector);

        return array_map('floatval', $values);
    }

    /**
     * Calculate cosine similarity between two embeddings
     *
     * @return float Similarity score between 0 and 1
     */
    public static function cosineSimilarity(array $embedding1, array $embedding2): float
    {
        if (count($embedding1) !== count($embedding2)) {
            throw new Exception('Embeddings must have the same dimensions');
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $magnitude1 += $embedding1[$i] * $embedding1[$i];
            $magnitude2 += $embedding2[$i] * $embedding2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        // Convert cosine distance to similarity (1 - distance)
        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Check whether an embedding vector contains only zeros.
     */
    public static function isZeroVector(array $embedding): bool
    {
        if ($embedding === []) {
            return false;
        }

        foreach ($embedding as $value) {
            if ((float) $value != 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get metadata about the embedding configuration
     */
    public function getEmbeddingMetadata(): array
    {
        return [
            'embedding_model' => $this->model,
            'embedding_dimensions' => $this->dimensions,
            'embedding_generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate embeddings for a single text string
     *
     * @param  string  $text  The text to embed
     * @param  bool  $useCache  Whether to use cached embeddings (default: true)
     * @return array The embedding vector (1536 dimensions)
     *
     * @throws Exception
     */
    public function embed(string $text, bool $useCache = true, ?AiUsageContext $usageContext = null): array
    {
        if (empty(trim($text))) {
            throw new Exception('Cannot generate embedding for empty text');
        }

        $usageContext ??= auth()->user() ? new AiUsageContext(auth()->user(), 'embedding', 'search') : null;
        $truncatedText = $this->truncateText($text);
        $cacheKey = $this->cacheKey($truncatedText);

        // Use cache to avoid redundant API calls for the same text
        if ($useCache) {
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                try {
                    return $this->validateEmbedding($cached);
                } catch (Exception) {
                    Cache::forget($cacheKey);
                }
            }
        }

        $embeddings = $this->embedBatch([$truncatedText], $usageContext);

        if (! isset($embeddings[0])) {
            throw new Exception('OpenAI API returned no embedding data');
        }

        $embedding = $this->validateEmbedding($embeddings[0]);

        if ($useCache) {
            Cache::put($cacheKey, $embedding, now()->addDays(30));
        }

        return $embedding;
    }

    /**
     * Generate embeddings for multiple text strings in a single API call
     *
     * @param  array  $texts  Array of strings to embed
     * @return array Array of embedding vectors
     *
     * @throws Exception
     */
    public function embedBatch(array $texts, ?AiUsageContext $usageContext = null): array
    {
        if (empty($texts)) {
            return [];
        }

        foreach ($texts as $index => $text) {
            if (empty(trim($text))) {
                throw new Exception("Cannot generate embedding for empty text at index {$index}");
            }
        }

        // Truncate texts to avoid token limits (8191 tokens for text-embedding-3-small)
        $truncatedTexts = array_map(fn ($text) => $this->truncateText($text), $texts);
        $clientRequestId = (string) Str::uuid();
        $reservation = $usageContext ? app(AiUsageRecorder::class)->reserve(
            $usageContext,
            $this->model,
            max(1, (int) ceil(array_sum(array_map('strlen', $truncatedTexts)) / 4)),
            $clientRequestId,
        ) : null;
        $span = null;
        $usage = [];
        $requestSucceeded = false;

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'X-Client-Request-Id' => $clientRequestId,
            ];

            if ($this->organization) {
                $headers['OpenAI-Organization'] = $this->organization;
            }

            $span = start_ai_request_span($this->model, array_map(
                fn (string $text) => ['role' => 'user', 'content' => $text],
                array_values($truncatedTexts)
            ), []);

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->retry(3, 1000)
                ->post($this->apiUrl, [
                    'input' => array_values($truncatedTexts),
                    'model' => $this->model,
                    'dimensions' => $this->dimensions,
                ]);

            if (! $response->successful()) {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new Exception('OpenAI API returned error: ' . $response->body());
            }

            $data = $response->json();
            $usage = $data['usage'] ?? [];

            if (! isset($data['data']) || ! is_array($data['data'])) {
                throw new Exception('Invalid response from OpenAI API');
            }

            if (count($data['data']) !== count($texts)) {
                throw new Exception('OpenAI API returned an unexpected number of embeddings');
            }

            $embeddings = array_map(function ($item, $index) {
                if (! isset($item['embedding']) || ! is_array($item['embedding'])) {
                    throw new Exception("OpenAI API response missing embedding at index {$index}");
                }

                return $this->validateEmbedding($item['embedding']);
            }, $data['data'], array_keys($data['data']));

            $requestSucceeded = true;
            if ($reservation) {
                $this->completeAccountingSafely($reservation, $usage, $clientRequestId);
            }

            return $embeddings;
        } catch (Exception $e) {
            if ($reservation && ! $requestSucceeded) {
                $this->failAccountingSafely($reservation);
            }

            Log::error('Failed to generate embeddings', [
                'error' => $e->getMessage(),
                'texts_count' => count($texts),
            ]);

            throw $e;
        } finally {
            finish_ai_request_span($span, $usage, null, $requestSucceeded);
        }
    }

    private function cacheKey(string $truncatedText): string
    {
        return "embedding:v2:{$this->model}:{$this->dimensions}:" . hash('sha256', $truncatedText);
    }

    /** @param array<string, mixed> $usage */
    private function completeAccountingSafely(AiUsageReservation $reservation, array $usage, ?string $requestId): void
    {
        try {
            app(AiUsageRecorder::class)->complete($reservation, AiTokenUsage::fromArray($usage), $requestId);
        } catch (Exception $exception) {
            report($exception);
            Log::error('AI usage accounting failed after a completed embedding request', [
                'error' => redact_sensitive_urls($exception->getMessage()),
            ]);
        }
    }

    private function failAccountingSafely(AiUsageReservation $reservation): void
    {
        try {
            app(AiUsageRecorder::class)->fail($reservation);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * Validate a provider/cached embedding before it can be stored or reused.
     */
    private function validateEmbedding(array $embedding): array
    {
        if (count($embedding) !== $this->dimensions) {
            throw new Exception("Embedding vector has invalid dimensions: expected {$this->dimensions}, got " . count($embedding));
        }

        $normalized = [];

        foreach ($embedding as $index => $value) {
            if (! is_numeric($value)) {
                throw new Exception("Embedding vector contains a non-numeric value at index {$index}");
            }

            $normalized[] = (float) $value;
        }

        if (self::isZeroVector($normalized)) {
            throw new Exception('Embedding provider returned a zero vector');
        }

        return $normalized;
    }

    /**
     * Truncate text to approximately 8000 tokens (safe limit for text-embedding-3-small)
     */
    private function truncateText(string $text): string
    {
        // Rough estimate: 1 token ≈ 4 characters
        // Max tokens: 8191, we'll use 8000 to be safe
        $maxChars = 8000 * 4; // 32,000 characters

        if (mb_strlen($text) > $maxChars) {
            return mb_substr($text, 0, $maxChars);
        }

        return $text;
    }
}
