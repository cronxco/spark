<?php

namespace Tests\Feature\Actions;

use App\Models\ActionProgress;
use App\Models\User;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\ActionRegistry;
use App\Services\Actions\Handlers\DeleteEvent;
use Fiber;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActionExecutorLockingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep real lock ownership and release semantics, but fail contention
        // immediately so interleaved requests never wait on this test process.
        Cache::swap(new Repository(new class extends ArrayStore
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class($this, $name, $seconds, $owner) extends ArrayLock
                {
                    public function block($seconds, $callback = null)
                    {
                        return parent::block(0, $callback);
                    }
                };
            }
        }));
    }

    #[Test]
    public function overlapping_runs_share_one_receipt_and_execute_the_handler_once(): void
    {
        $user = User::factory()->create();
        $action = app(ActionRegistry::class)->find('events.delete');
        $executor = app(ActionExecutor::class);
        $target = ['event_id' => (string) Str::uuid()];
        $handler = Mockery::mock(DeleteEvent::class);
        $handler->shouldReceive('handle')->once()->andReturnUsing(function (): ActionOutcome {
            Fiber::suspend();

            return ActionOutcome::succeeded('Deleted');
        });
        $this->app->instance(DeleteEvent::class, $handler);

        $first = new Fiber(fn () => $executor->run($user, $action, $target, idempotencyKey: 'same-request'));
        $first->start();

        try {
            $executor->run($user, $action, $target, idempotencyKey: 'same-request');
            $this->fail('An overlapping request entered a locked action.');
        } catch (LockTimeoutException) {
            $this->assertSame(1, ActionProgress::where('action_type', 'command')->count());
        }

        $first->resume();
        $replay = $executor->run($user, $action, $target, idempotencyKey: 'same-request');
        $this->assertSame($first->getReturn()->id(), $replay->id());
        $this->assertSame(1, ActionProgress::where('action_type', 'command')->count());
    }

    #[Test]
    public function overlapping_undo_requests_reverse_the_effect_once(): void
    {
        $user = User::factory()->create();
        $action = app(ActionRegistry::class)->find('events.delete');
        $executor = app(ActionExecutor::class);
        $target = ['event_id' => (string) Str::uuid()];
        $handler = Mockery::mock(DeleteEvent::class);
        $handler->shouldReceive('handle')->once()->andReturn(ActionOutcome::succeeded('Deleted', undo: $target));
        $handler->shouldReceive('undo')->once()->andReturnUsing(function (): void {
            Fiber::suspend();
        });
        $this->app->instance(DeleteEvent::class, $handler);
        $receipt = $executor->run($user, $action, $target);

        $first = new Fiber(fn () => $executor->undo($user, $receipt->id()));
        $first->start();

        try {
            $executor->undo($user, $receipt->id());
            $this->fail('An overlapping request entered a locked Undo.');
        } catch (LockTimeoutException) {
            $this->assertTrue($first->isSuspended());
        }

        $first->resume();
        $replay = $executor->undo($user, $receipt->id());
        $this->assertTrue($replay->isUndone());
        $this->assertFalse($replay->canUndo());
        $this->assertSame($first->getReturn()->toArray()['undo'], $replay->toArray()['undo']);
    }
}
