<?php

namespace Tests\Unit\Services\Flint;

use App\Services\Flint\RoutineConfig;
use Tests\TestCase;

class RoutineConfigTest extends TestCase
{
    /**
     * @test
     */
    public function a_routine_uses_its_own_secret_when_it_has_one(): void
    {
        config([
            'services.flint_routine.secret' => 'shared',
            'services.flint_routine.routines.topics.secret' => 'topics-only',
        ]);

        $this->assertSame('topics-only', RoutineConfig::secret('topics'));
    }

    /**
     * @test
     */
    public function a_routine_without_its_own_secret_falls_back_to_the_shared_one(): void
    {
        config([
            'services.flint_routine.secret' => 'shared',
            'services.flint_routine.routines.topics.secret' => null,
        ]);

        $this->assertSame('shared', RoutineConfig::secret('topics'));
    }

    /**
     * @test
     */
    public function routines_do_not_share_a_secret_once_each_sets_its_own(): void
    {
        config([
            'services.flint_routine.secret' => 'shared',
            'services.flint_routine.routines.topics.secret' => 'topics-only',
            'services.flint_routine.routines.digest.secret' => 'digest-only',
        ]);

        $this->assertNotSame(RoutineConfig::secret('topics'), RoutineConfig::secret('digest'));
        $this->assertSame('digest-only', RoutineConfig::secret('digest'));
    }

    /**
     * @test
     */
    public function an_unset_secret_resolves_to_null_rather_than_an_empty_string(): void
    {
        config([
            'services.flint_routine.secret' => '',
            'services.flint_routine.routines.news_roundup.secret' => '',
        ]);

        $this->assertNull(RoutineConfig::secret('news_roundup'));
    }

    /**
     * @test
     */
    public function an_unset_url_resolves_to_null(): void
    {
        config(['services.flint_routine.routines.news_roundup.url' => null]);

        $this->assertNull(RoutineConfig::url('news_roundup'));
    }

    /**
     * @test
     */
    public function only_known_routines_are_recognised(): void
    {
        $this->assertTrue(RoutineConfig::isKnown('digest'));
        $this->assertTrue(RoutineConfig::isKnown('news_roundup'));
        $this->assertFalse(RoutineConfig::isKnown('not_a_routine'));
    }
}
