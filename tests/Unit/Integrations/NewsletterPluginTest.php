<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Newsletter\NewsletterPlugin;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterPluginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private NewsletterPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'newsletter',
        ]);
        $this->plugin = new NewsletterPlugin;
    }

    /** @test */
    public function it_has_correct_metadata()
    {
        $this->assertEquals('Newsletter', $this->plugin->getDisplayName());
        $this->assertEquals('knowledge', $this->plugin->getDomain());
        $this->assertEquals('fas.newspaper', $this->plugin->getIcon());
        $this->assertNotEmpty($this->plugin->getDescription());
    }

    /** @test */
    public function it_defines_newsletter_action_types()
    {
        $actionTypes = $this->plugin->getActionTypes();

        $this->assertArrayHasKey('received_post', $actionTypes);

        $newsletterAction = $actionTypes['received_post'];
        $this->assertEquals('Newsletter Post', $newsletterAction['display_name']);
        $this->assertEquals('fas.envelope-open-text', $newsletterAction['icon']);
        $this->assertTrue($newsletterAction['display_with_object']);
    }

    /** @test */
    public function it_defines_block_types()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('newsletter_summary_tweet', $blockTypes);
        $this->assertArrayHasKey('newsletter_summary_short', $blockTypes);
        $this->assertArrayHasKey('newsletter_summary_paragraph', $blockTypes);
        $this->assertArrayHasKey('newsletter_key_takeaways', $blockTypes);
        $this->assertArrayHasKey('newsletter_tldr', $blockTypes);

        $tweetBlock = $blockTypes['newsletter_summary_tweet'];
        $this->assertEquals('Tweet Summary', $tweetBlock['display_name']);
        $this->assertEquals('fab.twitter', $tweetBlock['icon']);
        $this->assertTrue($tweetBlock['display_with_object']);
        $this->assertEquals('info', $tweetBlock['accent_color']);
    }

    /** @test */
    public function it_defines_object_types()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('newsletter_publication', $objectTypes);
        $this->assertArrayHasKey('newsletter_user', $objectTypes);

        $publication = $objectTypes['newsletter_publication'];
        $this->assertEquals('Publication', $publication['display_name']);
        $this->assertEquals('fas.newspaper', $publication['icon']);
        $this->assertFalse($publication['hidden']);

        $user = $objectTypes['newsletter_user'];
        $this->assertEquals('Newsletter Reader', $user['display_name']);
        $this->assertTrue($user['hidden']);
    }

    /** @test */
    public function it_defines_instance_types()
    {
        $instanceTypes = $this->plugin->getInstanceTypes();

        $this->assertArrayHasKey('newsletters', $instanceTypes);

        $newsletters = $instanceTypes['newsletters'];
        $this->assertEquals('Newsletters', $newsletters['label']);
    }

    /** @test */
    public function it_supports_webhook_service_type()
    {
        $this->assertEquals('webhook', $this->plugin->getServiceType());
    }

    /** @test */
    public function it_returns_correct_identifier()
    {
        $this->assertEquals('newsletter', $this->plugin->getIdentifier());
    }

    /** @test */
    public function it_returns_info_accent_color()
    {
        $this->assertEquals('info', $this->plugin->getAccentColor());
    }
}
