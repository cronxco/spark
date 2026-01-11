<?php

namespace Tests\Feature;

use App\Livewire\GoCardlessReconfirmBanner;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoCardlessReconfirmBannerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function component_retrieves_institution_id_from_auth_metadata(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'gocardless',
            'account_id' => null,
            'auth_metadata' => [
                'access_token' => 'test_token',
                'gocardless_institution_id' => 'test_bank_institution_id',
                'gocardless_institution_name' => 'Test Bank',
                'gocardless_requisition_id' => 'test_requisition',
                'eua_expired' => true,
                'requires_reconfirmation' => true,
            ],
        ]);

        // Mount the component and verify it loads without errors
        $component = Livewire::test(GoCardlessReconfirmBanner::class, ['group' => $group])
            ->assertSet('group.id', $group->id)
            ->assertHasNoErrors();

        // Verify the institution ID is accessible from auth_metadata
        $this->assertEquals('test_bank_institution_id', $group->auth_metadata['gocardless_institution_id']);
    }

    #[Test]
    public function component_shows_error_when_institution_id_missing(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'gocardless',
            'account_id' => null,
            'auth_metadata' => [
                'access_token' => 'test_token',
                'gocardless_institution_name' => 'Test Bank',
                // Missing gocardless_institution_id
                'eua_expired' => true,
                'requires_reconfirmation' => true,
            ],
        ]);

        // Call createNewEua and expect an error
        Livewire::test(GoCardlessReconfirmBanner::class, ['group' => $group])
            ->call('createNewEua')
            ->assertHasErrors('general')
            ->assertSet('loading', false);
    }
}
