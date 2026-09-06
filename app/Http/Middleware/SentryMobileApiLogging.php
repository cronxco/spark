<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Metadata-only telemetry for the mobile API surface.
 *
 * Payloads are private by default: this middleware records the shape of a
 * request, never its content. Query values, request bodies and response bodies
 * are excluded outright rather than filtered, because a denylist of sensitive
 * field names cannot keep pace with the API — the previous implementation
 * redacted two keys and consequently wrote note text, financial data, search
 * terms and freshly minted bearer-token plaintext into the log channel.
 *
 * Anything added here must be a bounded, non-identifying enumeration. If you
 * find yourself wanting to log a value a user typed or a provider returned,
 * the answer is no.
 */
class SentryMobileApiLogging
{
    /**
     * Response envelope keys safe to record: counts and opaque pagination
     * cursors, never payload content.
     *
     * @var array<int, string>
     */
    private const SAFE_ENVELOPE_KEYS = ['has_more', 'next_cursor'];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // auth:sanctum and other guards throw exceptions rather than returning a
            // response. We take ownership here: report (no-op for non-reportable
            // exceptions like AuthenticationException) and render, so logging always
            // captures the resulting HTTP status code.
            $handler = app(ExceptionHandler::class);
            $handler->report($e);
            $response = $handler->render($request, $e);
        }

        $this->logRequest($request, $response, $startedAt);

        return $response;
    }

    private function logRequest(Request $request, Response $response, float $startedAt): void
    {
        $status = $response->getStatusCode();
        $content = $response->getContent();

        $context = [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'response_status' => $status,
            'response_size_bytes' => is_string($content) ? strlen($content) : 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $context += $this->requestShape($request);
        $context += $this->responseShape($status, $content);

        Log::channel('sentry_logs')->info(
            'Mobile API: ' . $request->method(),
            array_filter($context, fn ($v) => $v !== null),
        );
    }

    /**
     * Bounded facts about the request body — sizes and counts only.
     *
     * @return array<string, mixed>
     */
    private function requestShape(Request $request): array
    {
        if (! in_array($request->method(), ['POST', 'PATCH', 'PUT'], true) || ! $request->isJson()) {
            return [];
        }

        $body = $request->json()->all();

        if (! is_array($body)) {
            return [];
        }

        $shape = ['request_field_count' => count($body)];

        // HealthController batches up to 500 samples — the count is the useful signal.
        if (isset($body['samples']) && is_array($body['samples'])) {
            $shape['sample_count'] = count($body['samples']);
        }

        return $shape;
    }

    /**
     * Bounded facts about the response — item counts and pagination cursors.
     *
     * @return array<string, mixed>
     */
    private function responseShape(int $status, mixed $content): array
    {
        if ($status === 304 || $status === 204 || ! is_string($content) || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [];
        }

        $shape = [];

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $shape['item_count'] = count($decoded['data']);
        }

        foreach (self::SAFE_ENVELOPE_KEYS as $key) {
            if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                $shape[$key] = $decoded[$key];
            }
        }

        return $shape;
    }
}
