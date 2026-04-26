<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SentryMobileApiLogging
{
    private const BODY_SIZE_LIMIT = 4096;

    /** Request fields that contain device/push tokens — logged as [REDACTED] */
    private const SENSITIVE_FIELDS = ['apns_token', 'push_token'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $status = $response->getStatusCode();
        $content = $response->getContent();

        $context = [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'query' => $request->query() ?: null,
            'response_status' => $status,
            'response_size_bytes' => is_string($content) ? strlen($content) : 0,
        ];

        if (in_array($request->method(), ['POST', 'PATCH', 'PUT'], true) && $request->isJson()) {
            $context['request_summary'] = $this->summarizeRequestBody($request);
        }

        if ($status !== 304 && $status !== 204 && is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $context = array_merge($context, $this->summarizeResponsePayload($decoded, strlen($content)));
            }
        }

        Log::channel('sentry_logs')->info(
            'Mobile API: ' . $request->method() . ' ' . $request->path(),
            array_filter($context, fn ($v) => $v !== null),
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeRequestBody(Request $request): array
    {
        $body = $request->json()->all();

        // HealthController batches up to 500 samples — log count only, never the data
        if (isset($body['samples']) && is_array($body['samples'])) {
            return ['sample_count' => count($body['samples'])];
        }

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (isset($body[$field])) {
                $body[$field] = '[REDACTED]';
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function summarizeResponsePayload(array $decoded, int $byteLength): array
    {
        // Paginated envelope — extract metadata, skip the items array
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $summary = ['item_count' => count($decoded['data'])];

            if (isset($decoded['has_more'])) {
                $summary['has_more'] = $decoded['has_more'];
            }

            if (isset($decoded['next_cursor'])) {
                $summary['next_cursor'] = $decoded['next_cursor'];
            }

            return $summary;
        }

        // Small payload — include the full body
        if ($byteLength <= self::BODY_SIZE_LIMIT) {
            return ['response_body' => $decoded];
        }

        // Large non-paginated payload — top-level scalar fields only
        $scalars = array_filter($decoded, fn ($v) => is_scalar($v));

        return $scalars ? ['response_body' => $scalars] : [];
    }
}
