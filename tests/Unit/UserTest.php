<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_uuid_as_primary_key(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUuid($user->id));
    }

    public function test_user_id_is_not_auto_incrementing(): void
    {
        $user = new User();

        $this->assertFalse($user->incrementing);
        $this->assertEquals('string', $user->getKeyType());
    }

    public function test_user_uuid_is_generated_on_creation(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->id);
        $this->assertTrue(Str::isUuid($user->id));
    }

    public function test_initials_returns_first_letter_of_first_and_last_name(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);

        $this->assertEquals('JD', $user->initials());
    }

    public function test_initials_returns_first_letter_of_single_name(): void
    {
        $user = User::factory()->create(['name' => 'John']);

        $this->assertEquals('J', $user->initials());
    }

    public function test_initials_returns_first_two_words_only_for_multiple_names(): void
    {
        $user = User::factory()->create(['name' => 'John Michael Doe']);

        $this->assertEquals('JM', $user->initials());
    }

    public function test_initials_handles_empty_name(): void
    {
        $user = User::factory()->create(['name' => '']);

        $this->assertEquals('', $user->initials());
    }

    public function test_user_has_fillable_attributes(): void
    {
        $user = new User();

        $this->assertContains('name', $user->getFillable());
        $this->assertContains('email', $user->getFillable());
        $this->assertContains('password', $user->getFillable());
    }

    public function test_user_hides_sensitive_attributes(): void
    {
        $user = new User();

        $this->assertContains('password', $user->getHidden());
        $this->assertContains('remember_token', $user->getHidden());
    }

    public function test_user_casts_email_verified_at_to_datetime(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->email_verified_at);
    }

    public function test_user_password_is_hashed(): void
    {
        $user = User::factory()->create(['password' => 'plaintext']);

        // Password should not be stored as plaintext
        $this->assertNotEquals('plaintext', $user->password);
    }

    public function test_user_can_be_created_with_factory(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_user_can_be_unverified(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertNull($user->email_verified_at);
    }

    public function test_multiple_users_have_unique_uuids(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->assertNotEquals($user1->id, $user2->id);
    }
}
