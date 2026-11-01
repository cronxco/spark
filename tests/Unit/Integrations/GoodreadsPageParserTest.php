<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Goodreads\GoodreadsPageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GoodreadsPageParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/Goodreads/'.$name.'.html');
    }

    #[Test]
    public function extracts_hidden_shelf_fields_and_pairs_reread_sessions_by_id(): void
    {
        $page = (new GoodreadsPageParser)->shelf($this->fixture('shelf'), '42', 'read', 1, 'America/Los_Angeles');
        $this->assertNull($page['next_page']);
        $this->assertSame(1, $page['total']);
        $book = $page['items'][0];
        $this->assertSame('123', $book['book_id']);
        $this->assertSame('9001', $book['review_id']);
        $this->assertSame('Alex', $book['user_name']);
        $this->assertSame(5, $book['user_rating']);
        $this->assertSame(400, $book['num_pages']);
        $this->assertSame('A. Writer', $book['author_name']);
        $this->assertSame('1234567890', $book['isbn']);
        $this->assertSame('2020', $book['book_published']);
        $this->assertSame('2025-09-13', $book['user_date_added']);
        $this->assertSame([
            ['id' => 'amzn1grreading_sessionv1second', 'started_at' => '2026-10-11', 'finished_at' => '2026-10-19'],
            ['id' => 'amzn1grreading_sessionv1first', 'started_at' => '2025-09-13', 'finished_at' => '2025-09-15'],
        ], $book['reading_sessions']);
    }

    #[Test]
    public function follows_only_the_next_page_for_the_same_shelf_and_user(): void
    {
        preg_match('/<tr id="review_9001"[\s\S]*?<\/tr>/', $this->fixture('shelf'), $row);
        $rows = '';
        for ($id = 1; $id <= 50; $id++) {
            $rows .= str_replace('review_9001', 'review_'.$id, $row[0]);
        }
        $html = '<html><head><title>Alex’s books (51 books)</title></head><body><table>'.$rows.'</table><a class="next_page" href="/review/list/42?per_page=50&amp;shelf=read&amp;page=2">next</a></body></html>';
        $this->assertSame(2, (new GoodreadsPageParser)->shelf($html, '42', 'read', 1, 'Europe/London')['next_page']);
        $this->expectException(RuntimeException::class);
        (new GoodreadsPageParser)->shelf(str_replace('/review/list/42?', 'https://evil.example/review/list/42?', $html), '42', 'read', 1, 'Europe/London');
    }

    #[Test]
    public function accepts_a_verified_empty_shelf_but_rejects_missing_rows(): void
    {
        $empty = '<html><head><title>Alex’s books (0 books)</title></head><body><table id="books"></table></body></html>';
        $this->assertSame([], (new GoodreadsPageParser)->shelf($empty, '42', 'read', 1, 'Europe/London')['items']);
        $this->expectException(RuntimeException::class);
        (new GoodreadsPageParser)->shelf(str_replace('(0 books)', '(1 book)', $empty), '42', 'read', 1, 'Europe/London');
    }

    #[Test]
    public function rejects_duplicate_review_rows_even_when_the_row_count_matches(): void
    {
        $html = $this->fixture('shelf');
        preg_match('/<tr id="review_9001"[\s\S]*?<\/tr>/', $html, $row);
        $html = str_replace(['(1 book)', '</tbody>'], ['(2 books)', $row[0].'</tbody>'], $html);
        $this->expectException(RuntimeException::class);
        (new GoodreadsPageParser)->shelf($html, '42', 'read', 1, 'Europe/London');
    }

    #[Test]
    public function parses_percentage_page_and_starting_statuses_without_guessing_a_percentage(): void
    {
        $page = (new GoodreadsPageParser)->statuses($this->fixture('statuses'), '42', 1, 'America/Los_Angeles');
        $this->assertCount(3, $page['items']);
        $this->assertSame('UserStatus103', $page['items'][0]['guid']);
        $this->assertSame('123', $page['items'][0]['book_id']);
        $this->assertSame(63, $page['items'][0]['progress_percentage']);
        $this->assertSame('2026-10-17T22:06:00+00:00', $page['items'][0]['pubDate']);
        $this->assertNull($page['items'][1]['progress_percentage']);
        $this->assertSame(92, $page['items'][2]['progress_pages']);
        $this->assertSame('2025-12-01T22:08:00+00:00', $page['items'][2]['pubDate']);
        $this->assertSame(1, (new GoodreadsPageParser)->reviewPercentage($this->fixture('review'), '102'));
    }

    #[Test]
    public function honours_an_explicit_uk_display_timezone(): void
    {
        $parser = new GoodreadsPageParser;
        $this->assertSame('2026-10-17T14:06:00+00:00', $parser->timestamp('Oct 17, 2026 03:06PM', 'Europe/London', 'minute')['pubDate']);
        $this->assertSame('2025-12-01T14:08:00+00:00', $parser->timestamp('Dec 01, 2025 02:08PM', 'Europe/London', 'minute')['pubDate']);
        $this->expectException(RuntimeException::class);
        $parser->timestamp('Oct 17, 2026 03:06PM', 'Invalid/Zone', 'minute');
    }

    #[Test]
    public function extracts_read_status_ids_and_action_times_from_profile_activity(): void
    {
        $items = (new GoodreadsPageParser)->activity($this->fixture('activity'), '42', 'America/Los_Angeles');
        $this->assertSame(['ReadStatus203', 'ReadStatus202', 'ReadStatus201'], array_column($items, 'guid'));
        $this->assertSame(['finished_reading', 'start_reading', 'wants_to_read'], array_column($items, 'type'));
        $this->assertSame('2026-10-11T21:33:00+00:00', $items[1]['pubDate']);
        $this->assertSame('Example Book', $items[1]['title']);
    }

    #[Test]
    public function reads_edition_metadata_and_resolves_author_work_and_series_references(): void
    {
        $book = (new GoodreadsPageParser)->book($this->fixture('book'), '123');
        $this->assertSame('Example Book (Example Series, #2)', $book['title']);
        $this->assertSame('A description with <b>formatting</b>.', $book['book_description']);
        $this->assertSame('A. Writer', $book['author_name']);
        $this->assertSame('Example Series', $book['series_name']);
        $this->assertSame('2', $book['series_number']);
        $this->assertSame('2020', $book['book_published']);
        $this->assertSame(400, $book['num_pages']);
        $this->assertSame('9781234567890', $book['isbn13']);
    }

    #[Test]
    public function falls_back_to_json_ld_when_hydration_data_is_absent(): void
    {
        $html = '<html><head><title>Example</title></head><body><script type="application/ld+json">{"@type":"Book","name":"Example &amp; Book","numberOfPages":400,"author":[{"name":"A. Writer"}],"aggregateRating":{"ratingValue":4.25}}</script></body></html>';
        $book = (new GoodreadsPageParser)->book($html, '123');
        $this->assertSame('Example & Book', $book['title']);
        $this->assertSame(400, $book['num_pages']);
    }

    #[Test]
    #[DataProvider('invalidPages')]
    public function rejects_login_errors_and_truncated_html(string $html): void
    {
        $this->expectException(RuntimeException::class);
        (new GoodreadsPageParser)->shelf($html, '42', 'read', 1, 'Europe/London');
    }

    public static function invalidPages(): array
    {
        return [
            ['<html><head><title>Sign in</title></head><body>Sign in to Goodreads</body></html>'],
            ['<html><head><title>Page not found</title></head><body>Error</body></html>'],
            ['<html><head><title>Alex’s books (1 book)</title></head><body><table>'],
            ['<html><head><title>Unexpected layout</title></head><body></body></html>'],
        ];
    }
}
