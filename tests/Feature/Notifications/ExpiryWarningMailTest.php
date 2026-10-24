<?php

namespace Tests\Feature\Notifications;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Notifications\CookieExpiryWarning;
use App\Notifications\GoCardlessConsentExpiring;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpiryWarningMailTest extends TestCase
{
    #[Test]
    public function gocardless_consent_expiring_builds_its_mail(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id, 'service' => 'gocardless',
            'auth_metadata' => ['gocardless_institution_name' => 'Test Bank'],
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'gocardless',
        ]);

        $soon = (new GoCardlessConsentExpiring($group, $integration, now()->addDays(5), 5))->toMail($user);
        $urgent = (new GoCardlessConsentExpiring($group, $integration, now()->addDay(), 1))->toMail($user);

        $this->assertSame('info', $soon->level);
        $this->assertSame('error', $urgent->level);
        $this->assertStringContainsString('Reconnect Test Bank', $soon->subject);
        $this->assertStringContainsString('Test Bank', (string) $soon->render());
    }

    #[Test]
    public function cookie_expiry_warning_builds_its_mail(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $soon = (new CookieExpiryWarning($group, 'example.com', now()->addDays(3)->toDateTimeString(), 3))->toMail($user);
        $urgent = (new CookieExpiryWarning($group, 'example.com', now()->addDay()->toDateTimeString(), 1))->toMail($user);

        $this->assertSame('info', $soon->level);
        $this->assertSame('error', $urgent->level);
        $this->assertStringContainsString('example.com', (string) $soon->render());
    }
}
