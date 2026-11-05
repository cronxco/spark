<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginViewTest extends TestCase
{
    #[Test]
    public function login_page_shows_cronxid_button()
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Log in with CronxID');
        $response->assertSee('/auth/authelia/redirect');
    }
}
