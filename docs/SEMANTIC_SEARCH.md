# Semantic Search Implementation

This document describes the semantic search functionality implemented in Spark using OpenAI embeddings and PostgreSQL's pgvector extension.

## Overview

Semantic search allows users to search through events and blocks using natural language queries. Unlike traditional keyword search, semantic search understands the meaning and context of queries, returning results based on conceptual similarity rather than exact keyword matches.

### Key Features

- **Full-text semantic search** across Events and Blocks
- **Hybrid search** combining semantic similarity with metadata filters
- **Automatic embedding generation** on create/update
- **Background processing** via Laravel queues
- **Multi-tenant security** ensuring users only search their own data
- **OpenAI integration** using text-embedding-3-small model (1536 dimensions)
- **pgvector indexes** for fast approximate nearest neighbor search

## Architecture

### Components

1. **EmbeddingService** (`app/Services/EmbeddingService.php`)
   - Interfaces with OpenAI API
   - Generates 1536-dimensional embeddings
   - Handles batch processing and caching
   - Provides utility methods for vector operations

2. **Queue Jobs**
   - `GenerateEventEmbeddingJob` - Generates embeddings for events
   - `GenerateBlockEmbeddingJob` - Generates embeddings for blocks

3. **Model Observers**
   - `EventObserver` - Auto-generates embeddings when events are created/updated
   - `BlockObserver` - Auto-generates embeddings when blocks are created/updated

4. **Search API** (`app/Http/Controllers/Api/SearchApiController.php`)
   - `POST /api/search/events` - Search events
   - `POST /api/search/blocks` - Search blocks
   - `POST /api/search` - Unified search across both

5. **Artisan Command**
   - `php artisan embeddings:generate` - Backfill embeddings for existing records

## Setup

### 1. Environment Configuration

Add your OpenAI API credentials to `.env`:

```env
OPENAI_API_KEY=sk-...
OPENAI_ORGANIZATION=org-...  # Optional
OPENAI_EMBEDDING_MODEL=text-embedding-3-small  # Optional, defaults to text-embedding-3-small
```

### 2. Run Migrations

Update the database schema to use proper vector types:

```bash
php artisan migrate
```

This will:
- Convert `events.embeddings` from TEXT to vector(1536)
- Convert `blocks.embeddings` from TEXT to vector(1536)
- Convert `objects.embeddings` from vector(3) to vector(1536)
- Create HNSW indexes for fast vector similarity search

### 3. Generate Embeddings for Existing Data

Backfill embeddings for all existing events and blocks:

```bash
# Generate embeddings for all records
php artisan embeddings:generate

# Generate only for events
php artisan embeddings:generate --type=events

# Generate only for blocks
php artisan embeddings:generate --type=blocks

# Force regenerate even if embeddings exist
php artisan embeddings:generate --force

# Process in smaller batches (default: 100)
php artisan embeddings:generate --batch=50

# Limit number of records to process
php artisan embeddings:generate --limit=1000
```

### 4. Ensure Queue Workers Are Running

Embeddings are generated asynchronously via queue jobs:

```bash
# Start queue worker
php artisan queue:work

# Or using Horizon (if configured)
php artisan horizon
```

## Usage

### Spotlight Command Palette (Recommended)

The fastest way to use semantic search is through the Spotlight command palette (`Cmd+K`):

**How it works:**

1. Press `Cmd+K` (or `Ctrl+K` on Windows/Linux) to open Spotlight
2. Type a natural language query with 3+ words or 15+ characters
3. Semantic search automatically activates and shows AI-powered results
4. Results appear with a 🔍 icon and similarity percentage
5. Click any result to navigate to that event or block

**Example queries:**

- "payment failures from last week"
- "workout data from yesterday morning"
- "customer support tickets about billing"
- "health metrics showing low energy"
- "transactions over $100"

**Features:**

- **Automatic activation**: No special prefix needed - just type naturally
- **Smart threshold**: Only triggers for meaningful queries (3+ words)
- **Top results**: Shows top 3 events and top 3 blocks
- **High relevance**: Uses 80% similarity threshold for quality results
- **Graceful fallback**: Silently falls back to regular search if OpenAI unavailable
- **Performance**: Results appear alongside regular keyword search

**Visual indicators:**

- 🔍 icon marks semantic search results
- Similarity percentage shown (e.g., "85% match")
- Lower priority than exact keyword matches (intentional)

### API Usage

### Search Events

Search for events using natural language:

```bash
POST /api/search/events
Content-Type: application/json
Authorization: Bearer {api_token}

{
  "query": "payment failures last week",
  "integration_id": "uuid-optional",
  "service": "stripe",  // optional
  "domain": "payment",  // optional
  "action": "failed",   // optional
  "from_date": "2025-11-01",  // optional
  "to_date": "2025-11-15",    // optional
  "threshold": 1.0,  // optional, cosine distance threshold (0-2, lower = more similar)
  "limit": 20        // optional, max results (1-100)
}
```

**Response:**

```json
{
  "data": [
    {
      "id": "event-uuid",
      "service": "stripe",
      "domain": "payment",
      "action": "failed",
      "value": 5000,
      "value_unit": "cents",
      "time": "2025-11-08T14:30:00Z",
      "similarity": 0.8523,  // 0-1, higher is more similar
      "integration": {
        "id": "integration-uuid",
        "service": "stripe",
        "name": "My Stripe Account"
      },
      "actor": {
        "id": "object-uuid",
        "title": "Customer ABC",
        "type": "customer"
      },
      "blocks_count": 3
    }
  ],
  "meta": {
    "query": "payment failures last week",
    "threshold": 1.0,
    "limit": 20,
    "count": 15
  }
}
```

### Search Blocks

Search for blocks (rich content):

```bash
POST /api/search/blocks
Content-Type: application/json
Authorization: Bearer {api_token}

{
  "query": "workout data from yesterday",
  "event_id": "uuid-optional",
  "block_type": "fitness",  // optional
  "threshold": 1.0,
  "limit": 20
}
```

### Unified Search

Search across both events and blocks:

```bash
POST /api/search
Content-Type: application/json
Authorization: Bearer {api_token}

{
  "query": "health metrics from this month",
  "threshold": 1.0,
  "limit": 20
}
```

**Response includes both events and blocks**, sorted by similarity.

## Model Usage

### Event Model

```php
use App\Models\Event;

// Semantic search
$embedding = app(\App\Services\EmbeddingService::class)->embed("payment issues");
$events = Event::semanticSearch($embedding, threshold: 1.0, limit: 20)->get();

// Hybrid search (semantic + filters)
$events = Event::hybridSearch(
    $embedding,
    filters: [
        'service' => 'stripe',
        'domain' => 'payment',
        'from_date' => '2025-11-01'
    ],
    threshold: 1.0,
    limit: 20
)->get();

// Get searchable text
$text = $event->getSearchableText();
// Returns: "stripe payment failed 5000 cents"
```

### Block Model

```php
use App\Models\Block;

// Semantic search
$blocks = Block::semanticSearch($embedding, threshold: 1.0, limit: 20)->get();

// Hybrid search
$blocks = Block::hybridSearch(
    $embedding,
    filters: ['block_type' => 'fitness'],
    threshold: 1.0,
    limit: 20
)->get();

// Get searchable text
$text = $block->getSearchableText();
// Returns: "Heart Rate Monitor 75 bpm https://example.com"
```

## How It Works

### 1. Embedding Generation

When an event or block is created/updated:

1. **Observer triggers** (`EventObserver` or `BlockObserver`)
2. **Job dispatched** to background queue
3. **Searchable text extracted** using `getSearchableText()`
4. **OpenAI API called** to generate 1536-dimensional embedding
5. **Embedding stored** in database as PostgreSQL vector

### 2. Search Process

When a search is performed:

1. **Query text converted** to embedding via OpenAI
2. **Vector similarity calculated** using cosine distance (`<=>` operator)
3. **Results filtered** by user's integrations (multi-tenant security)
4. **Metadata filters applied** (service, domain, date range, etc.)
5. **Results sorted** by similarity (ascending distance)
6. **Distance converted** to similarity score (1 - distance)

### 3. Searchable Text Extraction

**Events:**
```
{service} {domain} {action} {value} {value_unit}
```

**Blocks:**
```
{title} {content} {url} {value} {value_unit}
```

## Performance

### Indexing Strategy

HNSW (Hierarchical Navigable Small World) indexes are used for fast approximate nearest neighbor search:

```sql
CREATE INDEX events_embeddings_idx ON events
USING hnsw (embeddings vector_cosine_ops);
```

**Performance characteristics:**
- **Query time:** Sub-second for datasets with millions of records
- **Index build time:** ~1-2 minutes per 100k records
- **Memory overhead:** ~50-100MB per 100k vectors

### Caching

Embeddings for identical queries are cached for 30 days to reduce API costs.

## Cost Estimation

Using OpenAI's `text-embedding-3-small` model:

**Pricing:** $0.02 per 1 million tokens

**Typical costs:**
- **Initial backfill** (10,000 events @ 100 tokens each): $0.02
- **Monthly updates** (20% churn @ 2,000 events): $0.004/month
- **Search queries** (1,000 queries @ 20 tokens each): <$0.001/month

**Total:** ~$0.02 one-time + $0.01/month ongoing

## Troubleshooting

### Embeddings not generating

1. Check OpenAI API key is configured:
   ```bash
   php artisan tinker
   >>> config('services.openai.api_key')
   ```

2. Verify queue workers are running:
   ```bash
   php artisan queue:work
   ```

3. Check job failures:
   ```bash
   php artisan queue:failed
   ```

4. Check logs for errors:
   ```bash
   tail -f storage/logs/laravel.log
   ```

### Search returns no results

1. Verify embeddings exist:
   ```sql
   SELECT COUNT(*) FROM events WHERE embeddings IS NOT NULL;
   ```

2. Check threshold value (increase if too strict):
   ```json
   { "threshold": 2.0 }  // More permissive
   ```

3. Verify user has access to integrations:
   ```sql
   SELECT * FROM integrations WHERE user_id = ?;
   ```

### Slow search queries

1. Verify HNSW indexes exist:
   ```sql
   \di events_embeddings_idx
   ```

2. Check query plan:
   ```sql
   EXPLAIN ANALYZE SELECT * FROM events
   WHERE embeddings <=> '[...]' < 1.0
   ORDER BY embeddings <=> '[...]' LIMIT 20;
   ```

3. Consider reducing `limit` parameter or increasing `threshold`

## Security

### Multi-tenant Isolation

All search queries automatically filter by the authenticated user's integrations:

```php
$userIntegrationIds = $request->user()->integrations()->pluck('id')->toArray();
Event::whereIn('integration_id', $userIntegrationIds)->...
```

Users can **only search data from their own integrations**.

### API Authentication

All search endpoints require Sanctum authentication:

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('search/events', ...);
});
```

## Future Enhancements

Potential improvements:

1. **Hybrid ranking** - Combine semantic similarity with metadata relevance
2. **Re-ranking** - Use more powerful models for top-K results
3. **Filters UI** - Add search interface to Livewire dashboard
4. **Entity search** - Extend to EventObjects
5. **Multi-modal search** - Include image/video content
6. **Local models** - Self-hosted embeddings for privacy
7. **Fine-tuning** - Domain-specific embedding models

## References

- [OpenAI Embeddings Guide](https://platform.openai.com/docs/guides/embeddings)
- [pgvector Documentation](https://github.com/pgvector/pgvector)
- [PostgreSQL Vector Operations](https://github.com/pgvector/pgvector#querying)
