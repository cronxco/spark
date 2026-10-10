<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationQueueCoverageTest extends TestCase
{
    #[Test]
    public function integration_and_fallback_queues_have_workers_in_every_environment(): void
    {
        foreach (['production', 'staging', 'local'] as $environment) {
            $queues = collect(config("horizon.environments.{$environment}"))
                ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
                ->all();

            foreach (['pull', 'tasks', 'effects', 'default', 'embeddings'] as $queue) {
                $this->assertContains($queue, $queues, "{$environment} must consume {$queue}");
            }
        }
    }
}
