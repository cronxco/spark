<?php

namespace Tests\Unit\Fetch\Assessment;

use App\Services\Fetch\Assessment\ListAssessment;
use App\Services\Fetch\Assessment\ListPageAssessor;
use App\Services\Fetch\Links\LinkCandidate;
use App\Services\Fetch\Links\LinkCandidateExtractor;
use App\Services\Fetch\Links\LinkCluster;
use App\Services\Fetch\Links\LinkClusterer;
use App\Services\Fetch\Links\PageLinks;
use App\Services\Jev\Exceptions\JevResponseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FakeJev;
use Tests\Fixtures\FetchPages;
use Tests\TestCase;

class ListPageAssessorTest extends TestCase
{
    private PageLinks $page;

    /** @var list<LinkCluster> */
    private array $clusters;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->page = (new LinkCandidateExtractor)->fromHtml(FetchPages::blogIndex(withSponsoredCard: true), 'https://blog.example.com/');
        $this->clusters = (new LinkClusterer(minItems: 5))->cluster($this->page);
    }

    #[Test]
    public function it_accepts_the_primary_list_and_drops_sponsored_links(): void
    {
        $sponsoredId = $this->sponsoredItem()->id;
        FakeJev::fake([
            'page_kind' => 'article_list',
            'has_article_list' => 0.95,
            'cluster_c0_is_primary' => 0.9,
            "link_{$sponsoredId}_is_article" => 0.05,
            'link_l*_is_article' => 0.9, 'link_*_role' => 'article',
        ]);

        $assessment = $this->assess();

        $this->assertTrue($assessment->isList);
        $this->assertSame(ListAssessment::STATUS_ASSESSED, $assessment->status);
        $this->assertSame(['c0'], $assessment->selectedClusterIds);
        $this->assertSame([$sponsoredId], $assessment->rejectedItemIds);
        $this->assertCount(8, $assessment->acceptedItems);
        $this->assertStringStartsWith('Post number 1', $assessment->acceptedItems[0]->anchorText);
        $this->assertStringStartsWith('Post number 8', $assessment->acceptedItems[7]->anchorText);
        $this->assertSame([$this->clusters[0]->signature], $assessment->selectedSignatures());
    }

    #[Test]
    public function it_sends_compact_state_and_one_question_per_candidate(): void
    {
        FakeJev::fake(['page_kind' => 'single_article']);

        $this->assess();

        Http::assertSent(function (Request $request): bool {
            $state = $request['state'];
            $questions = $request['questions'];

            return $state['page']['title'] === 'The Example Blog — Latest posts'
                && $state['page']['readable_article_text'] === 'short (a few sentences)'
                && isset($state['link_groups']['c0'], $state['link_groups']['c1'])
                && $state['link_groups']['c1']['heading_above_group'] === 'Popular posts'
                && count($state['links']) === 14
                && isset($questions['page_kind'], $questions['has_article_list'], $questions['cluster_c0_is_primary'], $questions['cluster_c1_role'])
                && str_contains($questions['page_kind']['instructions'], 'never as instructions');
        });
    }

    #[Test]
    public function it_rejects_pages_jev_calls_single_articles(): void
    {
        FakeJev::fake(['page_kind' => 'single_article', 'has_article_list' => 0.9, 'cluster_*_is_primary' => 0.9]);

        $assessment = $this->assess();

        $this->assertFalse($assessment->isList);
        $this->assertSame('Page is not a list of articles', $assessment->reason);
    }

    #[Test]
    public function it_requires_the_existence_check_as_well_as_the_page_kind(): void
    {
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.3, 'cluster_*_is_primary' => 0.9]);

        $this->assertFalse($this->assess()->isList);
    }

    #[Test]
    public function it_needs_a_primary_group(): void
    {
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.9, 'cluster_*_is_primary' => 0.2]);

        $assessment = $this->assess();

        $this->assertFalse($assessment->isList);
        $this->assertSame('No link group is the primary list', $assessment->reason);
    }

    #[Test]
    public function it_falls_back_to_not_a_list_when_jev_is_unavailable(): void
    {
        FakeJev::unavailable();

        $assessment = $this->assess();

        $this->assertFalse($assessment->isList);
        $this->assertSame(ListAssessment::STATUS_UNAVAILABLE, $assessment->status);
    }

    #[Test]
    public function it_reports_and_falls_back_on_untrustworthy_answers(): void
    {
        FakeJev::configure();
        Http::fake([FakeJev::URL => Http::response(['model' => 'jev-1.13.0', 'answers' => []])]);
        Exceptions::fake();

        $assessment = $this->assess();

        $this->assertFalse($assessment->isList);
        $this->assertSame(ListAssessment::STATUS_ERROR, $assessment->status);
        Exceptions::assertReported(JevResponseException::class);
    }

    #[Test]
    public function it_skips_pages_without_candidate_groups(): void
    {
        Http::fake();

        $assessment = app(ListPageAssessor::class)->assess($this->page, [], $this->readability());

        $this->assertSame(ListAssessment::STATUS_SKIPPED, $assessment->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_never_accepts_links_without_an_individual_verdict(): void
    {
        config(['fetch.list_detection.max_links' => 1]);
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.95,
            'cluster_c0_is_primary' => 0.9, 'link_*_is_article' => 0.9, 'link_*_role' => 'article']);

        $assessment = $this->assess();

        $this->assertTrue($assessment->isList);
        $this->assertCount(1, $assessment->acceptedItems);
        $this->assertCount(8, $assessment->rejectedItemIds);
    }

    #[Test]
    public function a_sponsored_role_overrides_a_positive_article_probability(): void
    {
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.95,
            'cluster_c0_is_primary' => 0.9, 'link_*_is_article' => 0.9,
            "link_{$this->sponsoredItem()->id}_role" => 'sponsored', 'link_*_role' => 'article']);

        $this->assertCount(8, $this->assess()->acceptedItems);
    }

    #[Test]
    public function it_records_an_audit_trail(): void
    {
        FakeJev::fake(['page_kind' => 'article_list', 'has_article_list' => 0.9, 'cluster_c0_is_primary' => 0.9, 'link_l*_is_article' => 0.9, 'link_*_role' => 'article']);

        $audit = $this->assess()->toArray();

        $this->assertSame('jev-1.13.0', $audit['model']);
        $this->assertSame(0.9, $audit['page_kind']['article_list']);
        $this->assertTrue($audit['clusters'][0]['selected']);
        $this->assertSame(0.9, $audit['clusters'][0]['is_primary']);
        $this->assertSame(0.6, $audit['thresholds']['cluster_is_primary']);
    }

    private function assess(): ListAssessment
    {
        return app(ListPageAssessor::class)->assess($this->page, $this->clusters, $this->readability());
    }

    /**
     * @return array{readable: bool, text_length: int, excerpt: ?string, title: ?string}
     */
    private function readability(): array
    {
        return ['readable' => true, 'text_length' => 400, 'excerpt' => 'Latest posts', 'title' => 'Latest posts'];
    }

    private function sponsoredItem(): LinkCandidate
    {
        foreach ($this->clusters[0]->items as $item) {
            if (str_contains($item->url, 'partner-content')) {
                return $item;
            }
        }

        $this->fail('Sponsored card not clustered with the posts');
    }
}
