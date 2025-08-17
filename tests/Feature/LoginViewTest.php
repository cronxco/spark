<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginViewTest extends TestCase
{
    /**
     * @test
     */
    public function login_page_shows_cronxid_button()
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Log in with CronxID');
        $response->assertSee('/auth/authelia/redirect');
    }
}
