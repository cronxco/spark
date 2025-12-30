<?php

namespace Tests\Feature\Notifications;

use Tests\TestCase;

class DailyDigestReadyTest extends TestCase
{
    /**
     * A basic feature test example.
     *
     * @test
     */
    public function example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
