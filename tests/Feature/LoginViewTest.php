<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginViewTest extends TestCase
{
    public function test_login_page_shows_cronxid_button()
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Log in with CronxID');
        $response->assertSee('/auth/authelia/redirect');
    }
} 