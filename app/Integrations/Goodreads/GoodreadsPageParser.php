<?php

namespace App\Integrations\Goodreads;

use Carbon\CarbonImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

class GoodreadsPageParser
{
    private DOMXPath $xpath;

    public function shelf(string $html, string $userId, string $shelf, int $page, string $timezone): array
    {
        $this->document($html);
        $title = $this->text('//title');
        if (! preg_match('/\(([\d,]+) books?\)/', $title, $matches)) {
            throw new RuntimeException('Goodreads shelf count is missing; the page may have changed.');
        }
        $total = (int) str_replace(',', '', $matches[1]);
        $name = preg_replace('/[’\']s .*$/u', '', $title);
        $items = [];
        foreach ($this->nodes('//tr[starts-with(@id,"review_")]') as $row) {
            $reviewId = substr($row->getAttribute('id'), 7);
            $bookLink = $this->attribute('.//td[contains(@class," title")]//a[contains(@href,"/book/show/")]', 'href', $row);
            $bookId = $this->id($bookLink, 'book/show');
            $bookTitle = $this->attribute('.//td[contains(@class," title")]//a', 'title', $row)
                ?: $this->text('.//td[contains(@class," title")]//a', $row);
            if (! ctype_digit($reviewId) || ! $bookId || $bookTitle === '') {
                throw new RuntimeException('Goodreads returned an incomplete shelf row.');
            }
            $sessions = $this->sessions($row);
            $added = $this->field('date_added', $row);
            $added = $this->calendarDate($added);
            $rating = $this->attribute('.//td[contains(@class," rating")]//*[@data-rating]', 'data-rating', $row);
            $authorLink = './/td[contains(@class," author")]//a';
            $items[] = [
                'review_id' => $reviewId,
                'guid' => 'https://www.goodreads.com/review/show/' . $reviewId,
                'link' => 'https://www.goodreads.com/review/show/' . $reviewId,
                'book_id' => $bookId,
                'title' => $bookTitle,
                'book_url' => 'https://www.goodreads.com/book/show/' . $bookId,
                'book_large_image_url' => $this->attribute('.//td[contains(@class," cover")]//img', 'src', $row),
                'author_name' => $this->authorName($this->text($authorLink, $row)),
                'author_url' => $this->absolute($this->attribute($authorLink, 'href', $row)),
                'isbn' => $this->field('isbn', $row) ?: null,
                'isbn13' => $this->field('isbn13', $row) ?: null,
                'num_pages' => $this->number($this->field('num_pages', $row)),
                'average_rating' => is_numeric($this->field('avg_rating', $row)) ? (float) $this->field('avg_rating', $row) : null,
                'book_published' => $this->year($this->field('date_pub', $row)) ?? $this->year($this->field('date_pub_edition', $row)),
                'user_id' => $userId,
                'user_name' => $name,
                'user_rating' => is_numeric($rating) && (float) $rating > 0 ? (int) $rating : null,
                'user_date_added' => $added,
                'reading_sessions' => $sessions,
                'shelf' => $shelf,
                'source_timezone' => $timezone,
            ];
        }
        $next = $this->nextPage('/review/list/' . $userId, $page, ['shelf' => $shelf]);
        $expected = min(50, max(0, $total - ($page - 1) * 50));
        if (count($items) !== $expected || count(array_unique(array_column($items, 'review_id'))) !== count($items)
            || ($total > $page * 50 && $next === null) || ($total <= $page * 50 && $next !== null)) {
            throw new RuntimeException('Goodreads shelf is incomplete; refusing to import a partial page.');
        }

        return ['items' => $items, 'next_page' => $next, 'total' => $total];
    }

    public function statuses(string $html, string $userId, int $page, string $timezone): array
    {
        $this->document($html);
        $title = $this->text('//title');
        if (! preg_match('/(?:Showing\s+([\d,]+)[–-]([\d,]+)\s+of\s+([\d,]+)|No Status Updates|No status updates)/u', $title . ' ' . $this->text('//h1'), $count)) {
            throw new RuntimeException('Goodreads status history is missing or unrecognised.');
        }
        $total = isset($count[3]) ? (int) str_replace(',', '', $count[3]) : 0;
        $items = [];
        foreach ($this->nodes('//div[starts-with(@id,"user_status_")]') as $row) {
            $id = substr($row->getAttribute('id'), 12);
            $headerPath = './/span[contains(@class,"user_status_header")]';
            $header = $this->text($headerPath, $row);
            $bookId = $this->id($this->attribute($headerPath . '//a[contains(@href,"/book/show/")]', 'href', $row), 'book/show');
            $date = $this->text('.//a[contains(@class,"greyText") and contains(@href,"/user_status/show/")]', $row);
            $progress = null;
            $pages = null;
            if (preg_match('/\bis\s+(\d+(?:\.\d+)?)%\s+done\b/i', $header, $m)) {
                $progress = (int) round((float) $m[1]);
            } elseif (preg_match('/\bis on page\s+([\d,]+)/i', $header, $m)) {
                $pages = (int) str_replace(',', '', $m[1]);
            } elseif (! preg_match('/\bis starting\b/i', $header)) {
                continue;
            }
            if (! ctype_digit($id) || ! $bookId || $date === '' || ($progress !== null && $progress > 100)) {
                throw new RuntimeException('Goodreads returned an incomplete reading update.');
            }
            $items[] = [
                'guid' => 'UserStatus' . $id,
                'status_id' => $id,
                'type' => 'progress',
                'book_id' => $bookId,
                'title' => $this->text($headerPath . '//a[contains(@href,"/book/show/")]', $row),
                'book_large_image_url' => $this->attribute('.//div[contains(@class,"bookCover")]//img', 'src', $row),
                'user_name' => $this->text($headerPath . '//a[contains(@href,"/user/show/")]', $row),
                'user_id' => $userId,
                'progress_percentage' => $progress,
                'progress_pages' => $pages,
                ...$this->timestamp($date, $timezone, 'minute'),
            ];
        }
        $rows = count($this->nodes('//div[starts-with(@id,"user_status_")]'));
        $next = $this->nextPage('/user_status/list/' . $userId, $page);
        $statusIds = array_map(fn ($node) => $node->getAttribute('id'), $this->nodes('//div[starts-with(@id,"user_status_")]'));
        if ($rows !== min(30, max(0, $total - ($page - 1) * 30)) || count(array_unique($statusIds)) !== $rows
            || ($total > $page * 30 && $next === null) || ($total <= $page * 30 && $next !== null)) {
            throw new RuntimeException('Goodreads status history is incomplete.');
        }

        return ['items' => $items, 'next_page' => $next, 'total' => $total];
    }

    public function activity(string $html, string $userId, string $timezone): array
    {
        $this->document($html);
        if (! $this->nodes('//a[contains(@href,"/review/list/' . $userId . '")]')) {
            throw new RuntimeException('Goodreads profile is private or unrecognised; check the browser login.');
        }
        $items = [];
        foreach ($this->nodes('//tr[contains(concat(" ",normalize-space(@class)," ")," update ")]') as $row) {
            $statusLink = $this->attribute('.//a[contains(@href,"/read_statuses/")]', 'href', $row);
            $statusId = $this->id($statusLink, 'read_statuses');
            if (! $statusId) {
                continue;
            }
            $text = $this->text('.//div[contains(concat(" ",@class," ")," updateAction ")]', $row) ?: $this->text('.', $row);
            $type = match (true) {
                str_contains($text, 'is currently reading') => 'start_reading',
                str_contains($text, 'finished reading') => 'finished_reading',
                str_contains($text, 'wants to read') => 'wants_to_read',
                default => null,
            };
            if ($type === null) {
                continue;
            }
            $bookId = $this->id($this->attribute('.//a[contains(@href,"/book/show/")]', 'href', $row), 'book/show');
            $date = $row->getAttribute('data-timestamp');
            if (! $bookId || $date === '') {
                throw new RuntimeException('Goodreads activity is missing a book or timestamp.');
            }
            $items[] = [
                'guid' => 'ReadStatus' . $statusId,
                'type' => $type,
                'book_id' => $bookId,
                'title' => $this->text('.//a[contains(concat(" ",@class," ")," bookTitle ")]', $row)
                    ?: $this->text('.//a[contains(@href,"/book/show/") and normalize-space(.)!=""]', $row),
                'user_name' => $this->text('.//a[contains(@href,"/user/show/' . $userId . '")]', $row),
                'user_id' => $userId,
                ...$this->timestamp($date, $timezone, 'minute'),
            ];
        }

        return $items;
    }

    public function book(string $html, string $bookId): array
    {
        $this->document($html);
        preg_match('~<script\b[^>]*\bid=["\']__NEXT_DATA__["\'][^>]*>([\s\S]*?)</script>~i', $html, $hydration);
        $json = $hydration[1] ?? '';
        $state = $json !== '' ? (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['props']['pageProps']['apolloState'] ?? []) : [];
        $book = collect($state)->first(fn ($node) => is_array($node) && ($node['__typename'] ?? null) === 'Book' && (string) ($node['legacyId'] ?? '') === $bookId);
        if ($book) {
            $work = $this->resolve($state, $book['work'] ?? []);
            $author = $this->resolve($state, $book['primaryContributorEdge']['node'] ?? []);
            $seriesEdge = $book['bookSeries'][0] ?? [];
            $series = $this->resolve($state, $seriesEdge['series'] ?? []);
            $published = $work['details']['publicationTime'] ?? $book['details']['publicationTime'] ?? null;

            return [
                'book_id' => $bookId,
                'title' => $book['titleComplete'] ?? $book['title'],
                'book_description' => $book['description'] ?? null,
                'book_large_image_url' => $book['imageUrl'] ?? null,
                'author_name' => $author['name'] ?? null,
                'author_url' => $author['webUrl'] ?? null,
                'isbn' => $book['details']['isbn'] ?? null,
                'isbn13' => $book['details']['isbn13'] ?? null,
                'num_pages' => $book['details']['numPages'] ?? null,
                'book_published' => $published !== null ? CarbonImmutable::createFromTimestampMs($published, 'UTC')->format('Y') : null,
                'average_rating' => $work['stats']['averageRating'] ?? null,
                'series_name' => $series['title'] ?? $series['name'] ?? null,
                'series_number' => $seriesEdge['userPosition'] ?? null,
                'series_url' => $series['webUrl'] ?? null,
            ];
        }
        preg_match_all('~<script\b[^>]*\btype=["\']application/ld\+json["\'][^>]*>([\s\S]*?)</script>~i', $html, $schemas);
        foreach ($schemas[1] as $json) {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($data['@type'] ?? null) !== 'Book' || empty($data['name'])) {
                continue;
            }

            return [
                'book_id' => $bookId,
                'title' => html_entity_decode($data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'book_description' => $this->text('//*[@data-testid="description"]') ?: ($data['description'] ?? null),
                'book_large_image_url' => $data['image'] ?? null,
                'author_name' => $data['author'][0]['name'] ?? null,
                'author_url' => $data['author'][0]['url'] ?? null,
                'isbn' => $data['isbn'] ?? null,
                'num_pages' => $data['numberOfPages'] ?? null,
                'average_rating' => $data['aggregateRating']['ratingValue'] ?? null,
                'book_published' => $this->year($data['datePublished'] ?? ''),
            ];
        }
        throw new RuntimeException('Goodreads book metadata is missing or unrecognised.');
    }

    public function reviewId(string $html): string
    {
        $this->document($html);

        return $this->id($this->attribute('//a[contains(@href,"/review/show/")]', 'href'), 'review/show')
            ?? throw new RuntimeException('Goodreads status has no review link.');
    }

    public function reviewPercentage(string $html, string $statusId): int
    {
        $this->document($html);
        $value = $this->text('//*[contains(@class,"readingTimeline")]//a[contains(@href,"/user_status/show/' . $statusId . '")]');
        if (! preg_match('/^(\d+(?:\.\d+)?)%$/', $value, $matches) || (float) $matches[1] > 100) {
            throw new RuntimeException('Cannot resolve the percentage of a Goodreads starting update.');
        }

        return (int) round((float) $matches[1]);
    }

    public function timestamp(string $raw, string $timezone, string $precision): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Invalid Goodreads display timezone. Use an IANA timezone such as Europe/London.');
        }
        $raw = trim($raw);
        if ($raw === '') {
            throw new RuntimeException('Goodreads timestamp is missing.');
        }
        $date = CarbonImmutable::parse($raw, $timezone);

        return ['pubDate' => $date->utc()->toIso8601String(), 'source_date' => $raw, 'source_timezone' => $timezone, 'time_precision' => $precision];
    }

    private function document(string $html): void
    {
        if (! str_contains(strtolower($html), '</html>')) {
            throw new RuntimeException('Goodreads HTML is incomplete.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $this->xpath = new DOMXPath($document);
        $title = $this->text('//title');
        if (preg_match('/^(?:Sign in|Page not found|Access Denied|Just a moment)/i', $title) || str_contains($this->text('//body'), 'This Profile is Private')) {
            throw new RuntimeException('Goodreads access failed; sign in through the Playwright browser and retry.');
        }
    }

    /** @return list<DOMElement> */
    private function nodes(string $query, ?DOMNode $context = null): array
    {
        return iterator_to_array($this->xpath->query($query, $context));
    }

    private function text(string $query, ?DOMNode $context = null): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->nodes($query, $context)[0]->textContent ?? ''));
    }

    private function attribute(string $query, string $name, ?DOMNode $context = null): string
    {
        return ($this->nodes($query, $context)[0] ?? null)?->getAttribute($name) ?? '';
    }

    private function field(string $name, DOMNode $row): string
    {
        return $this->text('.//td[contains(concat(" ",@class," ")," ' . $name . ' ")]//div[@class="value"]', $row);
    }

    private function sessions(DOMNode $row): array
    {
        $sessions = [];
        foreach (['date_started' => 'started_at', 'date_read' => 'finished_at'] as $field => $key) {
            foreach ($this->nodes('.//td[contains(@class," ' . $field . '")]//div[contains(@class,"editable_date")]', $row) as $index => $date) {
                preg_match('/' . preg_quote($field, '/') . '_([^\s]+)/', $date->getAttribute('class'), $matches);
                $id = $matches[1] ?? 'row_' . $index;
                $sessions[$id] ??= ['id' => $id, 'started_at' => null, 'finished_at' => null];
                $sessions[$id][$key] = $this->calendarDate($this->text('.//span[1]', $date));
            }
        }

        return array_values($sessions);
    }

    private function calendarDate(string $raw): ?string
    {
        return preg_match('/\b([A-Z][a-z]+ \d{1,2}, \d{4})\b/', $raw, $m)
            ? CarbonImmutable::parse($m[1], 'UTC')->toDateString() : null;
    }

    private function number(string $value): ?int
    {
        return preg_match('/[\d,]+/', $value, $m) ? (int) str_replace(',', '', $m[0]) : null;
    }

    private function year(string $value): ?string
    {
        return preg_match('/\b(\d{4})\b/', $value, $m) ? $m[1] : null;
    }

    private function authorName(string $name): string
    {
        $parts = explode(',', $name, 2);

        return count($parts) === 2 ? trim($parts[1]) . ' ' . trim($parts[0]) : $name;
    }

    private function id(string $url, string $path): ?string
    {
        return preg_match('~^/(?:' . preg_quote($path, '~') . ')/(\d+)(?:[^\d/].*)?$~', parse_url($url, PHP_URL_PATH) ?? '', $m) ? $m[1] : null;
    }

    private function absolute(string $url): ?string
    {
        return $url !== '' ? (str_starts_with($url, '/') ? 'https://www.goodreads.com' . $url : $url) : null;
    }

    private function nextPage(string $path, int $page, array $filters = []): ?int
    {
        $url = $this->attribute('//a[contains(concat(" ",@class," ")," next_page ") and @href]', 'href');
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        if ((isset($parts['host']) && $parts['host'] !== 'www.goodreads.com')
            || ! preg_match('~^' . preg_quote($path, '~') . '(?:-[^/]*)?$~', $parts['path'] ?? '')
            || (int) ($query['page'] ?? 0) !== $page + 1
            || array_diff_assoc($filters, $query) !== []) {
            throw new RuntimeException('Goodreads returned an invalid pagination link.');
        }

        return $page + 1;
    }

    private function resolve(array $state, array $node): array
    {
        return isset($node['__ref']) ? ($state[$node['__ref']] ?? []) : $node;
    }
}
