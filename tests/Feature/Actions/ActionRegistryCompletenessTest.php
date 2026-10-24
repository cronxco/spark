<?php

namespace Tests\Feature\Actions;

use App\Services\Actions\ActionCatalogue;
use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionKind;
use App\Services\Actions\ActionRegistry;
use App\Services\Actions\ConfirmPolicy;
use App\Services\Actions\Reversible;
use App\Spotlight\Queries\Actions\GlobalActionsQuery;
use App\Support\SparkAbility;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A1/A2. Fails when a visible action has no owner, capability, handler or
 * confirm policy. This is the test that would have caught the three inert
 * Spotlight events.
 */
class ActionRegistryCompletenessTest extends TestCase
{
    private const DOMAINS = ['navigation', 'integrations', 'flint', 'events', 'insights'];

    #[Test]
    public function the_registry_is_not_empty_and_ids_are_unique(): void
    {
        $definitions = ActionCatalogue::definitions();

        $this->assertNotEmpty($definitions);
        $this->assertCount(count($definitions), collect($definitions)->pluck('id')->unique());

        $this->expectException(LogicException::class);
        new ActionRegistry([$definitions[0], $definitions[0]]);
    }

    #[Test]
    public function every_action_has_an_owner_and_terse_copy(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $action->id, "{$action->id} id format");
            $this->assertContains($action->domain, self::DOMAINS, "{$action->id} has no known owning domain");
            $this->assertNotSame('', $action->title, "{$action->id} title");
            $this->assertLessThanOrEqual(32, strlen($action->title), "{$action->id} title is not terse");
            $this->assertNotSame('', $action->subtitle, "{$action->id} subtitle");
            $this->assertNotSame('', $action->icon, "{$action->id} icon");
            $this->assertTrue($action->surfaces !== [] || $action->handler !== null, "{$action->id} is on no surface and has no handler");
        }
    }

    #[Test]
    public function every_action_that_runs_on_the_server_has_a_capability_and_a_handler(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            if ($action->kind === ActionKind::Navigation) {
                continue;
            }

            $this->assertNotNull($action->capability, "{$action->id} names no capability");
            $this->assertContains($action->capability, SparkAbility::DELEGABLE, "{$action->id} uses an unknown capability");
            $this->assertNotNull($action->handler, "{$action->id} has no handler");
            $this->assertTrue(is_subclass_of($action->handler, ActionHandler::class), "{$action->id} handler is not an ActionHandler");
        }
    }

    #[Test]
    public function a_native_action_is_one_the_mobile_session_may_run(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            if ($action->kind === ActionKind::Navigation || ! $action->on('native')) {
                continue;
            }

            $this->assertContains($action->capability, SparkAbility::MOBILE_SESSION, "{$action->id} is offered natively but the app session lacks {$action->capability}");
        }
    }

    #[Test]
    public function every_action_declares_how_it_confirms(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            if ($action->kind === ActionKind::Data) {
                $this->assertNotSame(ConfirmPolicy::None, $action->confirm, "{$action->id} changes data but neither offers Undo nor confirms");
            }

            if ($action->confirm === ConfirmPolicy::Undo) {
                $this->assertTrue(is_subclass_of($action->handler, Reversible::class), "{$action->id} offers Undo but its handler is not Reversible");
            }

            if ($action->kind === ActionKind::Navigation) {
                $this->assertSame(ConfirmPolicy::None, $action->confirm, "{$action->id} navigates, so it cannot confirm");
            }
        }
    }

    #[Test]
    public function navigation_actions_point_at_a_real_route(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            if ($action->kind !== ActionKind::Navigation) {
                continue;
            }

            $this->assertNull($action->handler, "{$action->id} navigates, so it has no handler");
            $this->assertTrue(Route::has($action->destination['route']), "{$action->id} points at a missing route");
        }
    }

    #[Test]
    public function target_fields_use_a_known_type(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            foreach ($action->target as $name => $field) {
                $this->assertContains($field['type'], ['uuid', 'string', 'integer'], "{$action->id}.{$name} type");
            }

            $this->assertSame(array_keys($action->target), array_keys($action->rules()));
        }
    }

    #[Test]
    public function spotlight_offers_only_what_the_registry_can_run_without_input(): void
    {
        $visible = GlobalActionsQuery::visible();

        $this->assertNotEmpty($visible);

        $visible->each(function (ActionDefinition $action): void {
            $this->assertTrue($action->on('web'));
            $this->assertTrue($action->isNavigation() || $action->runsWithoutInput(), "{$action->id} needs input or confirmation, which \">\" mode cannot ask for");
        });
    }
}
