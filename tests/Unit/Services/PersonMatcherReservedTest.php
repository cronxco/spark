<?php

namespace Tests\Unit\Services;

use App\Models\Person;
use App\Models\User;
use App\Services\ManualLog\PersonMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonMatcherReservedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function flint_is_reserved_even_when_a_person_has_that_name(): void
    {
        $user = User::factory()->create();
        Person::create(['user_id' => $user->id, 'type' => 'person', 'title' => 'Flint Smith', 'time' => now(), 'metadata' => []]);
        Person::create(['user_id' => $user->id, 'type' => 'person', 'title' => 'Flint', 'time' => now(), 'metadata' => []]);

        $result = (new PersonMatcher)->check((string) $user->id, ['@Flint', 'flint', 'Dan']);

        $this->assertSame('reserved', $result[0]['state']);
        $this->assertSame([], $result[0]['matches']);
        $this->assertSame('reserved', $result[1]['state']);
        $this->assertSame('new', $result[2]['state']);
    }
}
