<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualLogPageHttpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manual_log_page_renders_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/log');

        $response->assertStatus(200);
        $response->assertSee('Log an Activity');
    }
}
