<?php

namespace App\Jobs\Data\Goodreads;

use App\Integrations\Goodreads\GoodreadsPageParser;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Services\Media\MediaDownloadHelper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GoodreadsPageData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'pages';
    }

    protected function process(): void
    {
        Cache::lock('goodreads:import:' . $this->integration->user_id, 300)->block(10, function () {
            $items = $this->rawData['items'];
            if ($this->rawData['kind'] === 'progress') {
                usort($items, fn ($a, $b) => strcmp($a['pubDate'], $b['pubDate']));
            }
            foreach ($items as $item) {
                $book = $this->book($item, $this->rawData['kind'] === 'shelf');
                if ($this->rawData['kind'] === 'book') {
                    $this->enrichBookEvents($book);

                    continue;
                }
                if ($this->rawData['kind'] === 'shelf') {
                    $entries = $this->shelfEntries($item);
                    $this->bindLegacyEvents($entries, $item);
                    foreach ($entries as $entry) {
                        $this->importEvent($book, $item, $entry);
                    }
                } else {
                    $value = $item['type'] === 'start_reading' ? 0 : $item['progress_percentage'];
                    $sourceId = $item['type'] === 'start_reading'
                        ? 'goodreads_is_reading_' . md5($item['guid'])
                        : 'goodreads_' . $value . '_' . md5($item['guid']);
                    $this->importEvent($book, $item, [...$item, 'action' => 'is_reading', 'value' => $value, 'source_id' => $sourceId]);
                }
                $this->refreshProgress($book->refresh());
                $this->series($book->refresh());
                $this->cover($book);
            }
        });
    }

    protected function createOrUpdateObject(array $objectData): EventObject
    {
        $query = EventObject::where('user_id', $this->integration->user_id)->where('type', $objectData['type']);
        if ($objectData['type'] === 'goodreads_book') {
            $id = $objectData['metadata']['book_id'];

            return $query->where(fn ($q) => $q->where('metadata->book_id', $id)->orWhere('metadata->book_id', (int) $id))->firstOrFail();
        }

        return $query->where('metadata->goodreads_user_id', $objectData['metadata']['goodreads_user_id'])->firstOrFail();
    }

    private function book(array $item, bool $snapshot): EventObject
    {
        $bookId = (string) $item['book_id'];
        $book = EventObject::where('user_id', $this->integration->user_id)->where('type', 'goodreads_book')
            ->where(fn ($query) => $query->where('metadata->book_id', $bookId)->orWhere('metadata->book_id', (int) $bookId))->first();
        $metadata = $book?->metadata ?? [];
        $fullTitle = ($item['title'] ?? null) ?: ($metadata['full_title'] ?? $book?->title);
        $title = $fullTitle;
        if (preg_match('/^(.+?)\s*\((.+?),?\s+#([\d.]+)\)$/', $fullTitle, $series)) {
            $title = trim($series[1]);
            $item['series_name'] ??= trim($series[2], ' ,');
            $item['series_number'] ??= $series[3];
        }
        if (EventObject::where('user_id', $this->integration->user_id)->where('concept', 'document')->where('type', 'goodreads_book')
            ->where('title', $title)->when($book, fn ($query) => $query->whereKeyNot($book->id))->exists()) {
            $title .= ' [Goodreads ' . $bookId . ']';
        }
        $fields = [
            'author_name' => 'author', 'author_url' => 'author_url', 'isbn' => 'isbn', 'isbn13' => 'isbn13',
            'num_pages' => 'num_pages', 'book_published' => 'published_year', 'average_rating' => 'average_rating',
            'series_name' => 'series_name', 'series_number' => 'series_number', 'series_url' => 'series_url',
            'details_fetched_at' => 'details_fetched_at', 'review_id' => 'review_id',
        ];
        foreach ($fields as $from => $to) {
            if (isset($item[$from]) && $item[$from] !== '') {
                $metadata[$to] = $item[$from];
            }
        }
        $metadata['book_id'] = $bookId;
        $metadata['full_title'] = $fullTitle;
        if ($snapshot && ($metadata['shelf_observed_at'] ?? '') <= $this->rawData['observed_at']) {
            $metadata['current_shelf'] = $item['shelf'];
            $metadata['shelf_observed_at'] = $this->rawData['observed_at'];
            $metadata['user_rating'] = $item['user_rating'];
            $metadata['date_added'] = $item['user_date_added'];
            $metadata['reading_sessions'] = $item['reading_sessions'];
            $finished = array_filter(array_column($item['reading_sessions'], 'finished_at'));
            $metadata['date_read'] = $finished ? max($finished) : null;
            if ($item['shelf'] === 'currently-reading') {
                $open = array_values(array_filter($item['reading_sessions'], fn ($session) => $session['finished_at'] === null && $session['started_at'] !== null));
                usort($open, fn ($a, $b) => strcmp($b['started_at'], $a['started_at']));
                $start = $open[0]['started_at'] ?? $item['user_date_added'];
                if ($start !== null && (! isset($metadata['reading_started_at'])
                    || CarbonImmutable::parse($metadata['reading_started_at'])->timezone($item['source_timezone'])->toDateString() !== $start)) {
                    $metadata['reading_started_at'] = CarbonImmutable::parse($start, $item['source_timezone'])->utc()->toIso8601String();
                }
            } elseif (isset($metadata['reading_started_at'])) {
                $metadata['previously_started_at'] = $metadata['reading_started_at'];
                unset($metadata['reading_started_at']);
            }
        }
        $data = [
            'concept' => 'document', 'type' => 'goodreads_book', 'title' => $title,
            'content' => ($item['book_description'] ?? null) ?: $book?->content,
            'metadata' => $metadata,
            'url' => $book?->url ?? $item['link'] ?? 'https://www.goodreads.com/book/show/' . $bookId,
            'media_url' => isset($item['details_fetched_at'])
                ? (($item['book_large_image_url'] ?? null) ?: $book?->media_url)
                : ($book?->media_url ?: ($item['book_large_image_url'] ?? null)),
        ];
        if ($book) {
            $book->fill($data);
            if ($book->isDirty()) {
                $book->save();
            }

            return $book;
        }

        return EventObject::create([...$data, 'user_id' => $this->integration->user_id, 'time' => $item['pubDate'] ?? $this->rawData['observed_at'] ?? now()]);
    }

    private function shelfEntries(array $item): array
    {
        $action = match ($item['shelf']) {
            'read' => 'finished_reading', 'to-read' => 'wants_to_read', default => 'is_reading',
        };
        $sessions = $item['reading_sessions'];
        if ($item['shelf'] === 'read') {
            $latest = $sessions[0] ?? null;
            $sessions = array_values(array_filter($sessions, fn ($session) => $session['finished_at'] !== null));
            if ($latest !== null && $latest['finished_at'] === null) {
                array_unshift($sessions, $latest);
            }
        } elseif ($item['shelf'] === 'currently-reading') {
            $sessions = array_values(array_filter($sessions, fn ($session) => $session['finished_at'] === null));
            usort($sessions, fn ($a, $b) => strcmp($b['started_at'] ?? '', $a['started_at'] ?? ''));
            $sessions = array_slice($sessions, 0, 1);
        } else {
            $sessions = [];
        }
        $sessions = $sessions ?: [['id' => null, 'started_at' => null, 'finished_at' => null]];
        $entries = [];
        foreach ($sessions as $session) {
            $sessionDate = $item['shelf'] === 'to-read' ? $item['user_date_added'] : ($item['shelf'] === 'read' ? $session['finished_at'] : $session['started_at']);
            $date = $sessionDate ?? $item['user_date_added'];
            $time = $date !== null
                ? $this->estimateDayTime($date, $item['source_timezone'])
                : ['pubDate' => $this->rawData['observed_at'], 'time_precision' => 'observed', 'source_date' => null, 'source_timezone' => $item['source_timezone']];
            $time['time_source'] = $sessionDate !== null ? 'reading_session' : ($date !== null ? 'date_added' : 'observation');
            foreach ($this->rawData['activity'] ?? [] as $activity) {
                if ($activity['book_id'] === $item['book_id'] && $activity['type'] === match ($action) {
                    'is_reading' => 'start_reading', default => $action,
                } && ($sessionDate === null || CarbonImmutable::parse($activity['pubDate'])->timezone($item['source_timezone'])->toDateString() === $sessionDate)) {
                    $time = array_intersect_key($activity, array_flip(['pubDate', 'time_precision', 'source_date', 'source_timezone']));
                    $time['read_status_guid'] = $activity['guid'];
                    $time['time_source'] = 'activity';
                    break;
                }
            }
            $entries[] = [
                'action' => $action,
                'value' => $action === 'is_reading' ? 0 : ($action === 'finished_reading' ? $item['user_rating'] : null),
                'source_id' => 'goodreads_' . $action . '_' . md5($item['guid'] . ($session['id'] ? ':' . $session['id'] : '')),
                'session_id' => $session['id'],
                ...$time,
            ];
        }

        return $entries;
    }

    private function bindLegacyEvents(array &$entries, array $item): void
    {
        $reviewId = $item['review_id'];
        $claimed = [];
        foreach ($this->accountEvents()->where(fn ($query) => $query->where('event_metadata->goodreads_review_id', $reviewId)
            ->orWhere('event_metadata->guid', 'like', '%/review/show/' . $reviewId . '%')->orWhere('event_metadata->link', 'like', '%/review/show/' . $reviewId . '%'))->orderBy('time')->get() as $event) {
            $metadata = $event->event_metadata ?? [];
            if (isset($metadata['goodreads_session_id']) || ($metadata['source_format'] ?? null) === 'html') {
                continue;
            }
            $guid = $metadata['guid'] ?? '';
            $link = $metadata['link'] ?? '';
            if (! preg_match('~/review/show/' . preg_quote($reviewId, '~') . '(?:\D|$)~', $guid . ' ' . $link)) {
                continue;
            }
            $candidates = [];
            $nearestSession = null;
            $distance = INF;
            foreach ($item['reading_sessions'] as $session) {
                $date = $session[$event->action === 'finished_reading' ? 'finished_at' : 'started_at'];
                if ($date !== null) {
                    $difference = abs($event->time->timestamp - CarbonImmutable::parse($date, $item['source_timezone'])->timestamp);
                    if ($difference < $distance) {
                        $distance = $difference;
                        $nearestSession = $session['id'];
                    }
                }
            }
            foreach ($entries as $index => $entry) {
                if (! isset($claimed[$index]) && $entry['action'] === $event->action
                    && ($nearestSession === null || $entry['session_id'] === $nearestSession)
                    && ($event->action !== 'is_reading' || (int) $event->value === 0)) {
                    $candidates[$index] = abs($event->time->timestamp - CarbonImmutable::parse($entry['pubDate'])->timestamp);
                }
            }
            if ($candidates !== []) {
                asort($candidates);
                $index = array_key_first($candidates);
                $entries[$index]['existing_event_id'] = $event->id;
                $claimed[$index] = true;
            }
        }
    }

    private function importEvent(EventObject $book, array $item, array $entry): void
    {
        $guid = $item['guid'];
        $isProgress = str_starts_with($guid, 'UserStatus');
        $query = $this->accountEvents()->where('action', $entry['action']);
        $existing = isset($entry['existing_event_id']) ? (clone $query)->find($entry['existing_event_id']) : null;
        $existing ??= (clone $query)->where('source_id', $entry['source_id'])->first();
        if (isset($entry['session_id'], $item['review_id'])) {
            $existing ??= (clone $query)->where('event_metadata->goodreads_session_id', $entry['session_id'])
                ->where('event_metadata->goodreads_review_id', $item['review_id'])->first();
        }
        if ($isProgress) {
            $existing ??= (clone $query)->where('event_metadata->guid', $guid)->first();
        } elseif ($entry['action'] === 'is_reading') {
            $day = CarbonImmutable::parse($entry['pubDate'])->timezone($entry['source_timezone'])->startOfDay();
            $startQuery = (clone $query)->where('target_id', $book->id)->where('value', 0)->whereBetween('time', [$day->utc(), $day->addDay()->utc()->subMicrosecond()]);
            if (isset($entry['session_id'])) {
                $startQuery->where(fn ($q) => $q->whereNull('event_metadata->goodreads_session_id')->orWhere('event_metadata->goodreads_session_id', $entry['session_id']));
            }
            $readStatus = $entry['read_status_guid'] ?? (str_starts_with($guid, 'ReadStatus') ? $guid : null);
            if ($readStatus !== null) {
                $startQuery->where(fn ($q) => $q->where('event_metadata->guid', $readStatus)->orWhere('event_metadata->read_status_guid', $readStatus)
                    ->orWhere(fn ($unbound) => $unbound->whereNull('event_metadata->read_status_guid')
                        ->where(fn ($g) => $g->whereNull('event_metadata->guid')->orWhere('event_metadata->guid', 'not like', 'ReadStatus%'))));
            }
            $existing ??= $startQuery->first();
        }
        $metadata = [
            'source_format' => 'html', 'guid' => $guid, 'link' => $item['link'] ?? null,
            'goodreads_review_id' => $item['review_id'] ?? null, 'goodreads_session_id' => $entry['session_id'] ?? null,
            'source_date' => $entry['source_date'], 'source_timezone' => $entry['source_timezone'], 'time_precision' => $entry['time_precision'],
            'time_source' => $entry['time_source'] ?? (str_starts_with($guid, 'ReadStatus') ? 'activity' : 'status'),
        ];
        if ($isProgress) {
            $metadata['goodreads_status_id'] = $item['status_id'];
        }
        if (isset($entry['read_status_guid'])) {
            $metadata['read_status_guid'] = $entry['read_status_guid'];
        }
        $unit = $entry['action'] === 'is_reading' ? '%' : ($entry['value'] !== null ? '/5' : null);
        if ($existing) {
            $old = $existing->event_metadata ?? [];
            $isLegacy = ! isset($old['source_format']) || ($old['legacy_timestamp_preserved'] ?? false);
            if ($isLegacy) {
                $metadata['legacy_timestamp_preserved'] = true;
                $metadata['time_precision'] = $old['time_precision'] ?? 'second';
                $metadata['time_source'] = $old['time_source'] ?? 'rss';
                $metadata['guid'] = $old['guid'] ?? $guid;
            }
            $entry['pubDate'] = $this->settleEstimate($entry, $existing);
            $differentDay = CarbonImmutable::instance($existing->time)->timezone($entry['source_timezone'])->toDateString()
                !== CarbonImmutable::parse($entry['pubDate'])->timezone($entry['source_timezone'])->toDateString();
            $newRank = $this->precision($entry['time_precision']);
            $oldRank = $this->precision($old['time_precision'] ?? 'observed');
            // An estimate is recomputed on every sync, so it must never replace an equal estimate or the time would drift
            $replaceTime = ! $isLegacy && ($differentDay || $newRank > $oldRank || ($newRank === $oldRank && $entry['time_precision'] !== 'estimated'));
            if (! $isLegacy && ! $replaceTime) {
                foreach (['time_precision', 'source_date', 'source_timezone', 'time_source'] as $key) {
                    $metadata[$key] = $old[$key] ?? $metadata[$key];
                }
            }
            $changes = ['target_id' => $book->id, 'value' => $entry['value'], 'value_unit' => $unit, 'event_metadata' => array_merge($old, array_filter($metadata, fn ($v) => $v !== null))];
            if ($replaceTime) {
                $changes['time'] = $entry['pubDate'];
            }
            $existing->fill($changes);
            if ($existing->isDirty()) {
                $existing->save();
            }
            $this->eventBookDetails($existing, $book, ! $isProgress);
            $this->storeStart($book, $entry, $existing->time->toIso8601String());

            return;
        }
        if ($isProgress && $this->suppressProgress($book, $item, $entry)) {
            return;
        }
        $actor = $this->actor($item);
        $created = $this->createEvents([[
            'source_id' => $entry['source_id'], 'time' => $entry['pubDate'], 'domain' => 'media', 'action' => $entry['action'],
            'value' => $entry['value'], 'value_unit' => $unit, 'event_metadata' => array_filter($metadata, fn ($v) => $v !== null),
            'actor' => ['concept' => 'user', 'type' => 'goodreads_user', 'title' => $actor->title, 'metadata' => $actor->metadata],
            'target' => ['concept' => 'document', 'type' => 'goodreads_book', 'title' => $book->title, 'metadata' => $book->metadata],
            'blocks' => [],
        ]]);
        foreach ($created as $event) {
            $this->eventBookDetails($event, $book, ! $isProgress);
            $this->storeStart($book, $entry, $event->time->toIso8601String());
        }
    }

    private function storeStart(EventObject $book, array $entry, string $time): void
    {
        if ($entry['action'] !== 'is_reading' || $entry['value'] !== 0 || isset($entry['status_id'])) {
            return;
        }
        $metadata = $book->fresh()->metadata;
        if (in_array($metadata['current_shelf'] ?? null, ['read', 'to-read'], true)) {
            return;
        }
        $start = $metadata['reading_started_at'] ?? null;
        if ($start === null || CarbonImmutable::parse($time)->gte($start)) {
            $metadata['reading_started_at'] = $time;
            $book->update(['metadata' => $metadata]);
        }
    }

    private function actor(array $item): EventObject
    {
        $id = (string) ($item['user_id'] ?? $this->integration->group->auth_metadata['user_id']);
        $name = ($item['user_name'] ?? null) ?: 'Goodreads User';
        $query = EventObject::where('user_id', $this->integration->user_id)->where('type', 'goodreads_user');
        $actor = (clone $query)->where('metadata->goodreads_user_id', $id)->first()
            ?? (clone $query)->where('title', $name)->whereNull('metadata->goodreads_user_id')->first();
        if (! $actor) {
            if ((clone $query)->where('title', $name)->exists()) {
                $name .= ' [Goodreads ' . $id . ']';
            }

            return EventObject::create([
                'user_id' => $this->integration->user_id, 'concept' => 'user', 'type' => 'goodreads_user',
                'title' => $name, 'metadata' => ['goodreads_user_id' => $id], 'time' => now(),
            ]);
        }
        $actor->update(['metadata' => array_merge($actor->metadata ?? [], ['goodreads_user_id' => $id])]);

        return $actor;
    }

    private function suppressProgress(EventObject $book, array $item, array $entry): bool
    {
        $metadata = $book->metadata;
        $ignored = $metadata['suppressed_progress_guids'] ?? [];
        if (isset($ignored[$item['guid']])) {
            return true;
        }
        $time = CarbonImmutable::parse($entry['pubDate']);
        $last = $this->events($book)->where('action', 'is_reading')->whereBetween('time', [$time->subHours(6), $time])->latest('time')->first();
        if ($last && $entry['value'] >= $last->value && $entry['value'] - $last->value < 5) {
            $ignored[$item['guid']] = $entry['pubDate'];
            $metadata['suppressed_progress_guids'] = $ignored;
            if (($metadata['current_progress_time'] ?? '') <= $entry['pubDate']) {
                $metadata['current_progress_time'] = $entry['pubDate'];
                $metadata['current_progress_observed'] = $entry['value'];
            }
            $book->update(['metadata' => $metadata]);

            return true;
        }

        return false;
    }

    private function refreshProgress(EventObject $book): void
    {
        $metadata = $book->metadata;
        $latestQuery = $this->events($book)->whereIn('action', ['is_reading', 'finished_reading']);
        if (isset($metadata['reading_started_at'])) {
            $latestQuery->where('time', '>=', $metadata['reading_started_at']);
        }
        $latest = $latestQuery->latest('time')->first();
        $progress = $latest ? ($latest->action === 'finished_reading' ? 100 : (int) $latest->value) : 0;
        if (isset($metadata['current_progress_time']) && (! $latest || CarbonImmutable::parse($metadata['current_progress_time'])->gt($latest->time))
            && (! isset($metadata['reading_started_at']) || CarbonImmutable::parse($metadata['current_progress_time'])->gte($metadata['reading_started_at']))) {
            $progress = $metadata['current_progress_observed'];
        }
        $metadata['current_progress'] = match ($metadata['current_shelf'] ?? null) {
            'read' => 100, 'to-read' => null, default => $progress,
        };
        if ($metadata !== $book->metadata) {
            $book->update(['metadata' => $metadata]);
        }
    }

    private function enrichBookEvents(EventObject $book): void
    {
        foreach ($this->events($book)->with('blocks')->get() as $event) {
            $this->eventBookDetails($event, $book, ! str_starts_with($event->event_metadata['guid'] ?? '', 'UserStatus'));
        }
        $this->series($book);
        $this->cover($book);
    }

    private function eventBookDetails(Event $event, EventObject $book, bool $block): void
    {
        if ($book->metadata['author'] ?? null) {
            $event->attachTag($book->metadata['author'], 'goodreads_author');
        }
        if (! $block) {
            return;
        }
        $existing = $event->blocks()->where('block_type', 'book')->first();
        $data = [
            'time' => $event->time, 'title' => $book->title, 'metadata' => array_intersect_key($book->metadata, array_flip(['isbn', 'num_pages', 'published_year'])),
            'url' => $event->event_metadata['link'] ?? $book->url, 'media_url' => $book->media_url,
        ];
        if ($existing) {
            $existing->fill([...$data, 'metadata' => array_merge($existing->metadata ?? [], $data['metadata'])]);
            if ($existing->isDirty()) {
                $existing->save();
            }
        } elseif ($book->media_url) {
            $event->createBlock([...$data, 'time' => $event->time, 'block_type' => 'book']);
        }
    }

    private function series(EventObject $book): void
    {
        $metadata = $book->metadata;
        if (empty($metadata['series_name'])) {
            return;
        }
        $series = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id, 'concept' => 'collection', 'type' => 'goodreads_series', 'title' => $metadata['series_name'],
        ], ['metadata' => ['total_books' => null], 'url' => $metadata['series_url'] ?? null, 'time' => $book->time]);
        Relationship::createRelationship([
            'user_id' => $this->integration->user_id, 'from_type' => EventObject::class, 'from_id' => $book->id,
            'to_type' => EventObject::class, 'to_id' => $series->id, 'type' => 'part_of', 'metadata' => ['series_order' => $metadata['series_number'] ?? null],
        ]);
    }

    private function cover(EventObject $book): void
    {
        if (! $book->media_url || ! isset($book->metadata['details_fetched_at']) || ($book->metadata['cover_downloaded_url'] ?? null) === $book->media_url) {
            return;
        }
        try {
            $media = app(MediaDownloadHelper::class)->downloadAndAttachMedia($book->media_url, $book, 'downloaded_images', ['alt' => $book->title]);
            if ($media) {
                $book->update(['metadata' => [...$book->fresh()->metadata, 'cover_downloaded_url' => $book->media_url]]);
            }
        } catch (Throwable $e) {
            logger()->warning('Goodreads cover download failed', ['book_id' => $book->metadata['book_id'], 'error' => $e->getMessage()]);
        }
    }

    private function events(EventObject $book): Builder
    {
        return $this->accountEvents()->where('target_id', $book->id);
    }

    private function accountEvents(): Builder
    {
        return Event::where('service', 'goodreads')
            ->whereHas('integration', fn ($query) => $query->where('user_id', $this->integration->user_id)->where('integration_group_id', $this->integration->integration_group_id));
    }

    private function precision(string $precision): int
    {
        return match ($precision) {
            'second' => 4, 'minute' => 3, 'estimated' => 2, 'day' => 1, default => 0,
        };
    }

    /**
     * Goodreads shelves give a calendar date with no time. Midnight in Goodreads' display timezone (often
     * America/Los_Angeles) lands at 08:00 UK time, so estimate instead: the moment Spark first saw the book when
     * the date is today, otherwise midday in the user's own timezone.
     */
    private function estimateDayTime(string $date, string $sourceTimezone): array
    {
        $userTimezone = $this->integration->user?->getTimezone() ?? 'UTC';
        $observed = CarbonImmutable::parse($this->rawData['observed_at']);
        $isToday = $observed->timezone($sourceTimezone)->toDateString() === $date
            || $observed->timezone($userTimezone)->toDateString() === $date;
        $moment = $isToday ? $observed : CarbonImmutable::parse($date . ' 12:00:00', $userTimezone);

        return ['pubDate' => $moment->utc()->toIso8601String(), 'time_precision' => 'estimated', 'source_date' => $date, 'source_timezone' => $sourceTimezone];
    }

    /**
     * For an event already stored, the best estimate of today's activity is when Spark first created it,
     * which is earlier and closer to the truth than the current sync.
     */
    private function settleEstimate(array $entry, Event $existing): string
    {
        if ($entry['time_precision'] !== 'estimated' || ! $existing->created_at) {
            return $entry['pubDate'];
        }
        $userTimezone = $this->integration->user?->getTimezone() ?? 'UTC';
        $created = CarbonImmutable::instance($existing->created_at);
        $sameDay = $created->timezone($entry['source_timezone'])->toDateString() === $entry['source_date']
            || $created->timezone($userTimezone)->toDateString() === $entry['source_date'];

        return $sameDay ? $created->utc()->toIso8601String() : $entry['pubDate'];
    }
}
