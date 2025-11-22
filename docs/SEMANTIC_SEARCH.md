# Semantic Search

Semantic search enables natural language queries across Events, Blocks, and EventObjects using OpenAI embeddings and PostgreSQL pgvector.

## Overview

Semantic search understands the meaning and context of queries, returning results based on conceptual similarity rather than exact keyword matches. The system generates 1536-dimensional embeddings via OpenAI's text-embedding-3-small model, stores them in PostgreSQL vector columns with HNSW indexes, and supports hybrid search combining semantic similarity with metadata filters.

## Architecture

### Components

| Component | Location | Purpose |
|-----------|----------|---------|
| EmbeddingService | `app/Services/EmbeddingService.php` | Interfaces with OpenAI API, generates embeddings, handles batch processing |
| DuplicateDetectionService | `app/Services/DuplicateDetectionService.php` | Finds semantic duplicates using vector similarity |
| GenerateEventEmbeddingJob | `app/Jobs/Embeddings/` | Background job for event embedding generation |
| GenerateBlockEmbeddingJob | `app/Jobs/Embeddings/` | Background job for block embedding generation |
| GenerateObjectEmbeddingJob | `app/Jobs/Embeddings/` | Background job for EventObject embedding generation |
| EventObserver | `app/Observers/` | Auto-generates embeddings on event create/update |
| BlockObserver | `app/Observers/` | Auto-generates embeddings on block create/update |
| EventObjectObserver | `app/Observers/` | Auto-generates embeddings on object create/update |
| SemanticSearchController | `app/Http/Controllers/Api/SemanticSearchController.php` | External API for semantic search |
| SearchApiController | `app/Http/Controllers/Api/` | Internal search endpoints |

### Data Flow

**Embedding Generation:**

1. Model observer triggers on create/update
2. Job dispatched to background queue
3. Searchable text extracted via `getSearchableText()`
4. OpenAI API generates 1536-dimensional embedding
5. Embedding stored in PostgreSQL vector column with metadata

**Search Process:**

1. Query text converted to embedding via OpenAI
2. Vector similarity calculated using cosine distance (`<=>` operator)
3. Results filtered by user's integrations (multi-tenant security)
4. Metadata filters applied (service, domain, date range)
5. Results sorted by similarity with optional temporal weighting

**Searchable Text Format:**

```
Events:  {service} {domain} {action} {value} {value_unit}
Blocks:  {title} {content} {url} {value} {value_unit}
```

## Usage

### Basic Usage

**Spotlight Command Palette:**

Press `Cmd+K` (or `Ctrl+K`) and type a natural language query with 3+ words. Semantic search activates automatically and shows results with similarity percentages.

**Dedicated Semantic Mode:**

Type `~` followed by your query (e.g., `~payment issues`) for semantic-only results with broader matching and stronger temporal weighting.

**Model Methods:**

```php
use App\Models\Event;
use App\Services\EmbeddingService;

$embedding = app(EmbeddingService::class)->embed("payment issues");

// Basic semantic search
$events = Event::semanticSearch($embedding, threshold: 1.0, limit: 20)->get();

// Hybrid search with filters
$events = Event::hybridSearch(
    $embedding,
    filters: ['service' => 'stripe', 'from_date' => '2025-11-01'],
    threshold: 1.0,
    limit: 20,
    temporalWeight: 0.01
)->get();
```

### Advanced Usage

**External API:**

```bash
POST /api/search/semantic
Content-Type: application/json
Authorization: Bearer {sanctum_token}

{
  "query": "workout data from last week",
  "models": ["events", "blocks", "objects"],
  "threshold": 1.0,
  "limit": 20,
  "temporal_weight": 0.01
}
```

**Response:**

```json
{
  "query": "workout data from last week",
  "results": {
    "events": [
      {
        "id": "event-uuid",
        "service": "oura",
        "action": "had_workout",
        "similarity": 0.8745,
        "url": "https://spark.example.com/events/event-uuid"
      }
    ],
    "blocks": [...],
    "objects": [...]
  },
  "counts": { "events": 5, "blocks": 3, "objects": 2 }
}
```

**Internal Search Endpoints:**

```bash
# Search events
POST /api/search/events
{ "query": "...", "service": "stripe", "threshold": 1.0, "limit": 20 }

# Search blocks
POST /api/search/blocks
{ "query": "...", "block_type": "fitness", "threshold": 1.0, "limit": 20 }

# Unified search
POST /api/search
{ "query": "...", "threshold": 1.0, "limit": 20 }
```

**Temporal Weighting:**

| Days Ago | Weight 0.01 | Weight 0.015 | Weight 0.02 |
|----------|-------------|--------------|-------------|
| Today | 0% penalty | 0% penalty | 0% penalty |
| 7 days | 7% penalty | 10.5% penalty | 14% penalty |
| 30 days | 30% penalty | 45% penalty | 60% penalty |

**Duplicate Detection:**

```php
use App\Services\DuplicateDetectionService;

$service = app(DuplicateDetectionService::class);
$duplicates = $service->findDuplicateEvents($userId, similarityThreshold: 0.95, limit: 100);

foreach ($duplicates as $dup) {
    echo "{$dup['id1']} <-> {$dup['id2']}: " . round($dup['similarity'] * 100, 1) . "%\n";
}
```

## Configuration

### Options

| Parameter | Default | Range | Description |
|-----------|---------|-------|-------------|
| threshold | 1.0 | 0-2 | Cosine distance threshold (lower = more similar) |
| limit | 10 | 1-100 | Maximum results per model type |
| temporal_weight | 0.01 | 0-1 | Recency boost factor (penalty per day old) |
| models | all | events, blocks, objects | Model types to search |

### Environment Variables

```env
# Required
OPENAI_API_KEY=sk-...

# Optional
OPENAI_ORGANIZATION=org-...
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

### Embedding Metadata

Embeddings include versioning metadata stored in the model's `metadata` JSON column:

```json
{
  "embedding_model": "text-embedding-3-small",
  "embedding_dimensions": 1536,
  "embedding_generated_at": "2025-11-16T15:30:45+00:00"
}
```

## Development

### Adding Embeddings

**Run Migrations:**

```bash
php artisan migrate
```

This creates vector(1536) columns and HNSW indexes on events, blocks, and event_objects tables.

**Backfill Existing Data:**

```bash
# Generate for all records without embeddings
php artisan embeddings:generate

# Specific model type
php artisan embeddings:generate --type=events

# Force regenerate all
php artisan embeddings:generate --force

# Custom batch size
php artisan embeddings:generate --batch=50 --limit=1000
```

**Batch Re-embedding:**

```bash
# Regenerate all
php artisan embeddings:regenerate

# Specific model with filtering
php artisan embeddings:regenerate --model=Event --filter=service:monzo --force

# Test synchronously
php artisan embeddings:regenerate --limit=100 --sync
```

**Queue Workers:**

```bash
php artisan horizon
# Or: php artisan queue:work
```

### Testing

**Factory Configuration:**

```php
// Without embeddings (default, fast)
$event = Event::factory()->create();

// With embeddings (for semantic search tests)
$event = Event::factory()->withEmbeddings()->create();
$block = Block::factory()->withEmbeddings()->create();
$object = EventObject::factory()->withEmbeddings()->create();
```

**Testing Semantic Search:**

```php
public function test_semantic_search_finds_similar_events()
{
    $event = Event::factory()->withEmbeddings()->create();

    $this->mock(EmbeddingService::class)
        ->shouldReceive('embed')
        ->andReturn($event->embeddings);

    $embedding = app(EmbeddingService::class)->embed('payment');
    $results = Event::semanticSearch($embedding, threshold: 1.0)->get();

    $this->assertCount(1, $results);
}
```

**Observer Behavior:**

Observers check for `OPENAI_API_KEY` before dispatching jobs, so tests run without API configuration.

### Troubleshooting

**Embeddings not generating:**

```bash
# Check API key
php artisan tinker
>>> config('services.openai.api_key')

# Check queue failures
php artisan queue:failed

# Check logs
tail -f storage/logs/laravel.log
```

**Search returns no results:**

```sql
-- Verify embeddings exist
SELECT COUNT(*) FROM events WHERE embeddings IS NOT NULL;

-- Check user access
SELECT * FROM integrations WHERE user_id = ?;
```

**Slow queries:**

```sql
-- Verify indexes exist
\di events_embeddings_idx

-- Analyze query plan
EXPLAIN ANALYZE SELECT * FROM events
WHERE embeddings <=> '[...]' < 1.0
ORDER BY embeddings <=> '[...]' LIMIT 20;
```

### Cost Estimation

Using `text-embedding-3-small` at $0.02 per 1M tokens:

| Operation | Volume | Cost |
|-----------|--------|------|
| Initial backfill | 10,000 events @ 100 tokens | $0.02 |
| Monthly updates | 2,000 events | $0.004 |
| Search queries | 1,000 queries @ 20 tokens | <$0.001 |

### Performance

HNSW indexes provide sub-second queries for datasets with millions of records. Embeddings for identical queries are cached for 30 days.

## Related Documentation

- [OpenAI Embeddings Guide](https://platform.openai.com/docs/guides/embeddings)
- [pgvector Documentation](https://github.com/pgvector/pgvector)
- [Spotlight Command Palette](SPOTLIGHT.md)
- [Plugin Architecture](../CLAUDE.md#integration-plugin-system)
