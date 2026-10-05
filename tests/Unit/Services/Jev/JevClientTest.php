<?php

namespace Tests\Unit\Services\Jev;

use App\Services\Jev\Exceptions\JevResponseException;
use App\Services\Jev\Exceptions\JevUnavailableException;
use App\Services\Jev\JevClient;
use App\Services\Jev\JevQuestion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JevClientTest extends TestCase
{
    private const URL = 'https://api.typesafe.test/v1/systemone';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'services.jev.enabled' => true,
            'services.jev.api_key' => 'test-key',
            'services.jev.base_url' => 'https://api.typesafe.test',
            'services.jev.model' => 'jev-1.13.0',
        ]);
    }

    #[Test]
    public function it_sends_a_batch_of_typed_questions_and_returns_validated_answers(): void
    {
        Http::fake([self::URL => Http::response($this->body())]);

        $assessment = app(JevClient::class)->ask(['page' => ['title' => 'Latest posts']], $this->questions(), 3.0);

        $this->assertSame('jev-1.13.0', $assessment->model);
        $this->assertSame('article_list', $assessment->choice('page_kind'));
        $this->assertSame(0.8, $assessment->probability('page_kind', 'article_list'));
        $this->assertSame(0.0, $assessment->probability('page_kind', 'missing'));
        $this->assertSame(0.9, $assessment->noul('has_list'));
        $this->assertSame(0.7, $assessment->confidence('page_kind'));
        $this->assertSame(1.6, $assessment->score('density'));
        $this->assertSame(120, $assessment->inputTokens);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'jev-1.13.0'
                && $request['state'] === ['page' => ['title' => 'Latest posts']]
                && $request['questions']['page_kind']['type'] === 'choice'
                && $request['questions']['page_kind']['criteria'] === ['article' => null, 'article_list' => 'Many posts']
                && $request['questions']['has_list'] === ['type' => 'noul', 'instructions' => 'Is it a list?', 'criteria' => ['true' => 'Yes, a list']]
                && $request['questions']['density']['criteria'] === ['Few links', 'Some links', 'Mostly links'];
        });
    }

    #[Test]
    public function it_retries_rate_limits_within_the_budget(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('', 429, ['retry-after' => '0'])
            ->push('', 529)
            ->push($this->body()),
        ]);

        $assessment = app(JevClient::class)->ask('state', $this->questions(), 3.0);

        $this->assertSame('article_list', $assessment->choice('page_kind'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_reports_unavailable_after_exhausting_retries(): void
    {
        Http::fake([self::URL => Http::response('', 503)]);

        $this->expectException(JevUnavailableException::class);

        try {
            app(JevClient::class)->ask('state', $this->questions(), 3.0);
        } finally {
            Http::assertSentCount(3);
        }
    }

    #[Test]
    public function it_does_not_start_an_attempt_without_budget(): void
    {
        Http::fake();

        $this->expectException(JevUnavailableException::class);

        try {
            app(JevClient::class)->ask('state', $this->questions(), 0.05);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function it_is_unavailable_when_not_configured(): void
    {
        config(['services.jev.api_key' => null]);
        Http::fake();

        $this->assertFalse(app(JevClient::class)->isConfigured());
        $this->expectException(JevUnavailableException::class);

        app(JevClient::class)->ask('state', $this->questions());
    }

    #[Test]
    public function it_treats_client_errors_as_untrustworthy_responses(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(JevResponseException::class);

        app(JevClient::class)->ask('state', $this->questions(), 3.0);
    }

    #[Test]
    public function it_rejects_an_undeclared_choice(): void
    {
        $body = $this->body();
        $body['answers']['page_kind']['choice'] = 'recipe';
        $body['answers']['page_kind']['probabilities'] = ['recipe' => 0.8, 'article' => 0.2];
        Http::fake([self::URL => Http::response($body)]);

        $this->expectException(JevResponseException::class);
        $this->expectExceptionMessage("undeclared option 'recipe'");

        app(JevClient::class)->ask('state', $this->questions(), 3.0);
    }

    #[Test]
    public function it_rejects_missing_answers_and_bad_probabilities(): void
    {
        $missing = $this->body();
        unset($missing['answers']['has_list']);

        $outOfRange = $this->body();
        $outOfRange['answers']['has_list']['noul'] = 1.4;

        $badSum = $this->body();
        $badSum['answers']['page_kind']['probabilities'] = ['article' => 0.5, 'article_list' => 0.8];

        foreach ([$missing, $outOfRange, $badSum] as $body) {
            Http::fake([self::URL => Http::response($body)]);

            try {
                app(JevClient::class)->ask('state', $this->questions(), 3.0);
                $this->fail('Expected a JevResponseException');
            } catch (JevResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_an_unexpected_model_version(): void
    {
        $body = $this->body();
        $body['model'] = 'jev-2.0.0';
        Http::fake([self::URL => Http::response($body)]);

        $this->expectException(JevResponseException::class);

        app(JevClient::class)->ask('state', $this->questions(), 3.0);
    }

    #[Test]
    public function it_accepts_any_version_for_a_latest_alias(): void
    {
        config(['services.jev.model' => 'jev-latest']);
        Http::fake([self::URL => Http::response($this->body())]);

        $this->assertSame('jev-1.13.0', app(JevClient::class)->ask('state', $this->questions(), 3.0)->model);
    }

    #[Test]
    public function it_accepts_the_implicit_catch_all_option(): void
    {
        $body = $this->body();
        $body['answers']['page_kind']['probabilities'] = ['article' => 0.1, 'article_list' => 0.1, 'none_of_the_above' => 0.8];
        $body['answers']['page_kind']['choice'] = 'none_of_the_above';
        Http::fake([self::URL => Http::response($body)]);

        $this->assertSame('none_of_the_above', app(JevClient::class)->ask('state', $this->questions(), 3.0)->choice('page_kind'));
    }

    /**
     * @return array<string, JevQuestion>
     */
    private function questions(): array
    {
        return [
            'page_kind' => JevQuestion::choice('What kind of page is this?', ['article' => null, 'article_list' => 'Many posts']),
            'has_list' => JevQuestion::noul('Is it a list?', 'Yes, a list'),
            'density' => JevQuestion::score('How link-heavy is it?', ['Few links', 'Some links', 'Mostly links']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'page_kind' => ['type' => 'choice', 'choice' => 'article_list', 'probabilities' => ['article' => 0.2, 'article_list' => 0.8], 'confidence' => 0.7],
                'has_list' => ['type' => 'noul', 'noul' => 0.9],
                'density' => ['type' => 'score', 'score' => 1.6, 'probabilities' => ['0' => 0.1, '1' => 0.2, '2' => 0.7], 'confidence' => 0.5],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 10],
        ];
    }
}
