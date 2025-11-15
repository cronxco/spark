<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    private string $apiKey;
    private ?string $organization;
    private string $model;
    private string $apiUrl = 'https://api.openai.com/v1/embeddings';
    private int $dimensions = 1536;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->organization = config('services.openai.organization', env('OPENAI_ORGANIZATION'));
        $this->model = config('services.openai.embedding_model', 'text-embedding-3-small');

        if (empty($this->apiKey)) {
            throw new Exception('OpenAI API key is not configured');
        }
    }

    /**
     * Generate embeddings for a single text string
     *
     * @param string $text The text to embed
     * @param bool $useCache Whether to use cached embeddings (default: true)
     * @return array The embedding vector (1536 dimensions)
     * @throws Exception
     */
    public function embed(string $text, bool $useCache = true): array
    {
        if (empty(trim($text))) {
            // Return zero vector for empty text
            return array_fill(0, $this->dimensions, 0.0);
        }

        // Use cache to avoid redundant API calls for the same text
        if ($useCache) {
            $cacheKey = 'embedding:' . md5($text);
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }
        }

        $embeddings = $this->embedBatch([$text]);

        if ($useCache && !empty($embeddings)) {
            Cache::put('embedding:' . md5($text), $embeddings[0], now()->addDays(30));
        }

        return $embeddings[0] ?? array_fill(0, $this->dimensions, 0.0);
    }

    /**
     * Generate embeddings for multiple text strings in a single API call
     *
     * @param array $texts Array of strings to embed
     * @return array Array of embedding vectors
     * @throws Exception
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // Filter out empty strings
        $nonEmptyTexts = array_filter($texts, fn($text) => !empty(trim($text)));

        if (empty($nonEmptyTexts)) {
            // Return zero vectors for all texts
            return array_fill(0, count($texts), array_fill(0, $this->dimensions, 0.0));
        }

        // Truncate texts to avoid token limits (8191 tokens for text-embedding-3-small)
        $truncatedTexts = array_map(fn($text) => $this->truncateText($text), $nonEmptyTexts);

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ];

            if ($this->organization) {
                $headers['OpenAI-Organization'] = $this->organization;
            }

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->retry(3, 1000)
                ->post($this->apiUrl, [
                    'input' => array_values($truncatedTexts),
                    'model' => $this->model,
                    'dimensions' => $this->dimensions,
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new Exception('OpenAI API returned error: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new Exception('Invalid response from OpenAI API');
            }

            // Extract embeddings from response
            $embeddings = array_map(function ($item) {
                return $item['embedding'] ?? array_fill(0, $this->dimensions, 0.0);
            }, $data['data']);

            return $embeddings;
        } catch (Exception $e) {
            Log::error('Failed to generate embeddings', [
                'error' => $e->getMessage(),
                'texts_count' => count($texts),
            ]);

            // Return zero vectors as fallback
            return array_fill(0, count($texts), array_fill(0, $this->dimensions, 0.0));
        }
    }

    /**
     * Truncate text to approximately 8000 tokens (safe limit for text-embedding-3-small)
     *
     * @param string $text
     * @return string
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

    /**
     * Format embedding vector as PostgreSQL vector string
     *
     * @param array $embedding
     * @return string
     */
    public static function formatForPostgres(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }

    /**
     * Parse PostgreSQL vector string to array
     *
     * @param string|null $vector
     * @return array|null
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
     * @param array $embedding1
     * @param array $embedding2
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
}
