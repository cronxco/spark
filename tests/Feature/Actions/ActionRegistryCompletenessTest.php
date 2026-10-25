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
    private const DOMAINS = ['navigation', 'integrations', 'flint', 'events', 'insights', 'finance'];

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
            $this->assertNotEmpty($action->surfaces, "{$action->id} surfaces");
        }
    }

    #[Test]
    public function every_action_that_runs_on_the_server_has_a_capability_and_a_handler(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            if ($action->kind === ActionKind::Navigation || $action->isInterface()) {
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
            if ($action->kind === ActionKind::Navigation || $action->isInterface() || ! $action->on('native')) {
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

            if ($action->kind === ActionKind::Navigation || $action->isInterface()) {
                $this->assertSame(ConfirmPolicy::None, $action->confirm, "{$action->id} only opens something, so it cannot confirm");
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
            $this->assertTrue(isset($action->destination['route']) || isset($action->destination['native']), "{$action->id} has nowhere to go");
            if (isset($action->destination['route'])) {
                $this->assertTrue(Route::has($action->destination['route']), "{$action->id} points at a missing route");
            }
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

    #[Test]
    public function an_interface_action_names_the_event_its_page_listens_for(): void
    {
        $interface = collect(ActionCatalogue::definitions())->filter->isInterface();

        $this->assertNotEmpty($interface);

        foreach ($interface as $action) {
            $this->assertNotNull($action->event, "{$action->id} names no event");
            $this->assertNull($action->handler, "{$action->id} opens a page element, so the server has nothing to run");
            $this->assertSame(['web'], $action->surfaces, "{$action->id} is web only");
            $this->assertNotEmpty($action->contexts, "{$action->id} belongs to no page");
        }
    }

    #[Test]
    public function a_page_action_names_routes_that_exist_and_fields_it_targets(): void
    {
        foreach (ActionCatalogue::definitions() as $action) {
            foreach ($action->contexts as $route => $map) {
                $this->assertTrue(Route::has($route), "{$action->id} is offered on missing route {$route}");
                $this->assertSame(array_keys($action->target), array_keys($map), "{$action->id} must supply every target field on {$route}");
            }
        }
    }

    #[Test]
    public function every_event_a_page_action_dispatches_has_a_listener_on_its_page(): void
    {
        $pages = [
            'metrics.show' => base_path('app/Livewire/MetricDetail.php'),
            'money.show' => base_path('app/Livewire/FinancialAccountShow.php'),
            'integrations.details' => base_path('app/Livewire/IntegrationDetails.php'),
            'events.show' => resource_path('views/livewire/events/show.blade.php'),
            'objects.show' => resource_path('views/livewire/objects/show.blade.php'),
            'blocks.show' => resource_path('views/livewire/blocks/show.blade.php'),
        ];

        foreach (ActionCatalogue::definitions() as $action) {
            if (! $action->isInterface()) {
                continue;
            }

            foreach (array_keys($action->contexts) as $route) {
                $this->assertArrayHasKey($route, $pages, "{$action->id} is offered on {$route}, which this test does not know");
                $this->assertStringContainsString("'{$action->event}' =>", file_get_contents($pages[$route]), "{$action->id} dispatches {$action->event}, which {$route} does not listen for");
            }
        }
    }
}
