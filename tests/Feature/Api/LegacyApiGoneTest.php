<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyApiGoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function retiredRoutes(): array
    {
        return [
            'events index' => ['GET', '/api/events'],
            'event show' => ['GET', '/api/events/00000000-0000-0000-0000-000000000000'],
            'event create' => ['POST', '/api/events'],
            'event delete' => ['DELETE', '/api/events/00000000-0000-0000-0000-000000000000'],
            'search' => ['POST', '/api/search'],
            'semantic search' => ['POST', '/api/search/semantic'],
            'token create' => ['POST', '/api/tokens/create'],
            'token list' => ['GET', '/api/tokens'],
            'integrations' => ['GET', '/api/integrations'],
            'integration trigger' => ['POST', '/api/integrations/00000000-0000-0000-0000-000000000000/trigger'],
            'fetch bookmark' => ['POST', '/api/fetch/bookmarks'],
            'assistant context' => ['GET', '/api/assistant/context'],
            'flint answer' => ['POST', '/api/flint/questions/00000000-0000-0000-0000-000000000000/answer'],
            'task executions' => ['GET', '/api/task-executions'],
            'user' => ['GET', '/api/user'],
        ];
    }

    #[Test]
    #[DataProvider('retiredRoutes')]
    public function a_retired_route_answers_gone_with_a_pointer_to_v1(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->json($method, $uri)
            ->assertStatus(410)
            ->assertJsonPath('message', 'This endpoint has been retired. Use the /api/v1 equivalent.');
    }

    #[Test]
    #[DataProvider('retiredRoutes')]
    public function a_retired_route_answers_gone_without_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(410);
    }

    #[Test]
    public function the_oauth_exchange_and_v1_routes_are_not_caught(): void
    {
        $this->postJson('/api/oauth/token')->assertStatus(422);
        $this->getJson('/api/v1/events')->assertUnauthorized();
        $this->getJson('/api/v1/integrations')->assertUnauthorized();
    }
}
