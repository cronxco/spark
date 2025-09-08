<?php

namespace App\Integrations\Outline;

use App\Models\Integration;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutlineApi
{
    public function __construct(private readonly Integration $integration) {}

    public function listCollections(int $limit = 100): array
    {
        $endpoint = '/api/collections.list?limit=' . $limit;
        $data = $this->post($endpoint);
        $collections = $data['data'] ?? [];

        // Follow pagination with a sensible upper bound to avoid long-running jobs
        $pageCount = 0;
        while (isset($data['pagination']['nextPath']) && ! empty($data['pagination']['nextPath'])) {
            if ($pageCount++ >= 10) { // hard cap to prevent unbounded runtime
                Log::warning('Outline collections pagination capped at 10 pages');
                break;
            }

            $data = $this->post($data['pagination']['nextPath']);
            $collections = array_merge($collections, $data['data'] ?? []);
        }

        return $collections;
    }

    public function listDocuments(array $params = []): array
    {
        $query = array_merge([
            'limit' => 100,
        ], $params);

        $endpoint = '/api/documents.list?limit=' . (int) $query['limit'];
        unset($query['limit']);

        $data = $this->post($endpoint, $query);
        $documents = $data['data'] ?? [];

        // Follow pagination with a sensible upper bound to avoid long-running jobs
        $pageCount = 0;
        while (isset($data['pagination']['nextPath']) && ! empty($data['pagination']['nextPath'])) {
            if ($pageCount++ >= 10) { // hard cap to prevent unbounded runtime
                Log::warning('Outline documents pagination capped at 10 pages');
                break;
            }

            $data = $this->post($data['pagination']['nextPath'], $query);
            $documents = array_merge($documents, $data['data'] ?? []);
        }

        return $documents;
    }

    public function getDocument(string $documentId): array
    {
        $endpoint = '/api/documents.info';

        return $this->post($endpoint, [
            'id' => $documentId,
        ]);
    }

    public function createDocument(string $title, string $collectionId, ?string $parentId = null, bool $publish = true): array
    {
        $endpoint = '/api/documents.create';
        $payload = [
            'title' => $title,
            'collectionId' => $collectionId,
            'publish' => $publish,
        ];
        if (! empty($parentId)) {
            $payload['parentDocumentId'] = $parentId;
        }

        return $this->post($endpoint, $payload);
    }

    public function listPins(int $limit = 100): array
    {
        $endpoint = '/api/pins.list?limit=' . $limit;
        $data = $this->post($endpoint);

        // Pins API returns top-level keys like 'pins' and included 'documents'
        return $data;
    }

    public function createPin(string $documentId): array
    {
        $endpoint = '/api/pins.create';

        return $this->post($endpoint, [
            'documentId' => $documentId,
        ]);
    }

    public function deletePin(string $pinId): array
    {
        $endpoint = '/api/pins.delete';

        return $this->post($endpoint, [
            'id' => $pinId,
        ]);
    }

    public function updateDocumentContent(string $documentId, string $text, ?string $title = null, bool $publish = true): array
    {
        $endpoint = '/api/documents.update';
        $payload = [
            'id' => $documentId,
            'text' => $text,
            'publish' => $publish,
        ];
        if (! empty($title)) {
            $payload['title'] = $title;
        }

        return $this->post($endpoint, $payload);
    }

    protected function baseUrl(): string
    {
        $config = $this->integration->configuration ?? [];
        $raw = (string) ($config['api_url'] ?? config('services.outline.url'));
        $url = trim($raw);

        if ($url === '') {
            throw new Exception('Outline API URL is not configured');
        }

        // Ensure scheme is present; default to https
        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    protected function token(): string
    {
        $config = $this->integration->configuration ?? [];
        $raw = (string) ($config['access_token'] ?? config('services.outline.access_token') ?? '');
        $token = trim($raw);

        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        return $token;
    }

    protected function post(string $endpoint, array $json = []): array
    {
        $url = rtrim($this->baseUrl(), '/') . $endpoint;

        $payload = empty($json) ? (object) [] : $json;

        try {
            // API logging (request)
            $integrationId = (string) $this->integration->id;
            log_integration_api_request(
                'outline',
                'POST',
                $endpoint,
                [
                    'Authorization' => '[REDACTED]',
                    'Content-Type' => 'application/json',
                ],
                is_array($payload) ? $payload : (array) $payload,
                $integrationId,
                true
            );

            $startedAt = microtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type' => 'application/json',
            ])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(25)
                ->retry(2, 500)
                ->post($url, $payload);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            // API logging (response)
            log_integration_api_response(
                'outline',
                'POST',
                $endpoint,
                $response->status(),
                $response->body(),
                $response->headers(),
                $integrationId,
                true
            );

            if (! $response->successful()) {
                Log::warning('Outline API request failed', [
                    'url' => $url,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'duration_ms' => $durationMs,
                ]);
                throw new Exception('Outline API error: ' . $response->status());
            }

            $jsonResponse = $response->json();

            // Lightweight timing log in application log
            Log::debug('Outline API call completed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
            ]);

            return $jsonResponse;

        } catch (Throwable $e) {
            Log::error('Outline API request exception', [
                'url' => $url,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Outline API request failed: ' . $e->getMessage(), previous: $e);
        }
    }
}
