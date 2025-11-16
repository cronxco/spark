# Semantic Search Implementation

This document describes the semantic search functionality implemented in Spark using OpenAI embeddings and PostgreSQL's pgvector extension.

## Overview

Semantic search allows users to search through events and blocks using natural language queries. Unlike traditional keyword search, semantic search understands the meaning and context of queries, returning results based on conceptual similarity rather than exact keyword matches.

### Key Features

- **Full-text semantic search** across Events, Blocks, and EventObjects
- **Hybrid search** combining semantic similarity with metadata filters
- **Automatic embedding generation** on create/update
- **Background processing** via Laravel queues
- **Multi-tenant security** ensuring users only search their own data
- **OpenAI integration** using text-embedding-3-small model (1536 dimensions)
- **pgvector indexes** for fast approximate nearest neighbor search
- **Duplicate detection** using semantic similarity to identify duplicate content
- **Batch re-embedding** with filtering, progress tracking, and forced regeneration
- **Embedding health monitoring** dashboard for coverage metrics
- **External API** for semantic search from external tools (Sanctum authenticated)
- **Embedding versioning** tracking model, dimensions, and generation timestamps

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
   - `GenerateObjectEmbeddingJob` - Generates embeddings for EventObjects

3. **Model Observers**
   - `EventObserver` - Auto-generates embeddings when events are created/updated (only if API key configured)
   - `BlockObserver` - Auto-generates embeddings when blocks are created/updated (only if API key configured)
   - `EventObjectObserver` - Auto-generates embeddings when objects are created/updated (only if API key configured)

4. **DuplicateDetectionService** (`app/Services/DuplicateDetectionService.php`)
   - Finds semantic duplicates using vector similarity
   - Supports Events, Blocks, and EventObjects
   - Configurable similarity threshold (default 95%)

5. **Search APIs**
   - `SearchApiController` - Original search endpoints for internal use
   - `SemanticSearchController` (`app/Http/Controllers/Api/SemanticSearchController.php`) - External API for third-party tools
     - `POST /api/search/semantic` - Search across all models with Sanctum authentication

6. **Artisan Commands**
   - `php artisan embeddings:generate` - Backfill embeddings for existing records (legacy)
   - `php artisan embeddings:regenerate` - Batch re-embedding with advanced filtering and progress tracking

7. **Admin UI**
   - **Embedding Health Dashboard** (`/admin/sense-check`) - Coverage metrics and health monitoring
   - **Duplicate Detection** (`/admin/duplicates`) - Interactive UI for finding and managing duplicates

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

## Embedding Versioning

All embeddings include metadata tracking the model, dimensions, and generation timestamp. This metadata is stored in the model's `metadata` JSON column without requiring database schema changes.

### Metadata Structure

When an embedding is generated, the following metadata is automatically added to the model:

```json
{
  "embedding_model": "text-embedding-3-small",
  "embedding_dimensions": 1536,
  "embedding_generated_at": "2025-11-16T15:30:45+00:00"
}
```

### Querying by Embedding Version

You can query models by their embedding metadata:

```php
use App\Models\Event;

// Find events with specific embedding model
$events = Event::whereNotNull('embeddings')
    ->where('metadata->embedding_model', 'text-embedding-3-small')
    ->get();

// Find events missing embeddings or using old model
$needsUpdate = Event::where(function ($query) {
    $query->whereNull('embeddings')
          ->orWhere('metadata->embedding_model', '!=', 'text-embedding-3-small');
})->get();

// Find events generated before a specific date
$outdated = Event::whereNotNull('embeddings')
    ->where('metadata->embedding_generated_at', '<', '2025-11-01')
    ->get();
```

### Regenerating After Model Changes

If you change the embedding model (e.g., upgrade to a newer version):

1. Update `OPENAI_EMBEDDING_MODEL` in `.env`
2. Run batch re-embedding to update all embeddings:

```bash
# Regenerate all embeddings with new model
php artisan embeddings:regenerate --force

# Or regenerate specific model type
php artisan embeddings:regenerate --model=Event --force
```

The new metadata will automatically reflect the updated model version.

## Usage

### Spotlight Command Palette (Recommended)

The fastest way to use semantic search is through the Spotlight command palette (`Cmd+K`):

#### **Automatic Semantic Search (Default Mode)**

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
- **Temporal weighting**: Recent events get a small boost (1% per day old)

**Visual indicators:**

- 🔍 icon marks semantic search results
- Similarity percentage shown (e.g., "85% match")
- Lower priority than exact keyword matches (intentional)

#### **Dedicated Semantic Mode** (`~` prefix)

For semantic-only results with more options, use the dedicated mode:

1. Press `Cmd+K` to open Spotlight
2. Type `~` followed by your query (e.g., `~payment issues`)
3. Get up to 20 semantic results (10 events + 10 blocks)
4. Uses looser threshold (120% vs 80%) for broader matching
5. Stronger temporal weighting (1.5% per day) to prioritize recent events

**Why use semantic mode?**

- **More results**: 10 per type instead of 3
- **Broader matching**: Finds more distant matches
- **Recency focus**: Recent events boosted more heavily
- **Visual feedback**: Shows "days ago" labels (🔥 Today, ⏰ Yesterday, etc.)
- **Error visibility**: Displays helpful messages instead of silent fallback

**Example:**
```
~workout performance last month
```

Shows 10 fitness events and 10 related blocks, with recent workouts appearing first

### API Usage

#### External Semantic Search API (Recommended)

The `/api/search/semantic` endpoint provides unified semantic search across Events, Blocks, and EventObjects for external tools and applications.

**Authentication:** Requires Sanctum token authentication

**Endpoint:** `POST /api/search/semantic`

**Request:**

```bash
POST /api/search/semantic
Content-Type: application/json
Authorization: Bearer {sanctum_token}

{
  "query": "workout data from last week",
  "models": ["events", "blocks", "objects"],  // optional, defaults to all
  "threshold": 1.0,         // optional, cosine distance (0-2, lower = more similar)
  "limit": 20,              // optional, max results per model type (1-100)
  "temporal_weight": 0.01   // optional, recency boost (0-1, default: 0.01)
}
```

**Response:**

```json
{
  "query": "workout data from last week",
  "models": ["events", "blocks", "objects"],
  "results": {
    "events": [
      {
        "id": "event-uuid",
        "service": "oura",
        "domain": "health",
        "action": "had_workout",
        "value": 3500,
        "value_unit": "calories",
        "time": "2025-11-15T10:30:00Z",
        "similarity": 0.8745,
        "url": "https://spark.example.com/events/event-uuid",
        "actor": {
          "id": "object-uuid",
          "title": "John Doe",
          "type": "user"
        }
      }
    ],
    "blocks": [
      {
        "id": "block-uuid",
        "block_type": "workout_summary",
        "title": "Morning Workout",
        "time": "2025-11-15T10:30:00Z",
        "value": 45,
        "value_unit": "minutes",
        "similarity": 0.8523,
        "url": "https://spark.example.com/blocks/block-uuid",
        "event_id": "event-uuid"
      }
    ],
    "objects": [
      {
        "id": "object-uuid",
        "concept": "workout",
        "type": "activity",
        "title": "Running",
        "time": "2025-11-15T10:30:00Z",
        "similarity": 0.8234,
        "url": "https://spark.example.com/objects/object-uuid"
      }
    ]
  },
  "counts": {
    "events": 5,
    "blocks": 3,
    "objects": 2
  }
}
```

**Parameters:**

- `query` (required): Natural language search query
- `models` (optional): Array of model types to search - `["events", "blocks", "objects"]`
  - Defaults to all three if not specified
  - Can specify any combination: `["events"]`, `["events", "blocks"]`, etc.
- `threshold` (optional): Cosine distance threshold (0-2, lower = more similar)
  - Default: 1.0
  - Recommended: 0.8-1.2 for most use cases
- `limit` (optional): Maximum results per model type (1-100)
  - Default: 10
  - Applied independently to each model type
- `temporal_weight` (optional): Recency boost factor (0-1)
  - Default: 0.01 (1% penalty per day old)
  - Set to 0 for no temporal weighting
  - Higher values prioritize recent content more

**Error Responses:**

```json
// Validation error
{
  "message": "The query field is required.",
  "errors": {
    "query": ["The query field is required."]
  }
}

// Embedding service error
{
  "error": "Failed to generate embedding",
  "message": "OpenAI API error: ..."
}
```

**Example cURL Request:**

```bash
curl -X POST https://spark.example.com/api/search/semantic \
  -H "Authorization: Bearer your-sanctum-token" \
  -H "Content-Type: application/json" \
  -d '{
    "query": "payment failures last week",
    "models": ["events"],
    "threshold": 0.9,
    "limit": 20,
    "temporal_weight": 0.015
  }'
```

**Example Python Usage:**

```python
import requests

url = "https://spark.example.com/api/search/semantic"
headers = {
    "Authorization": "Bearer your-sanctum-token",
    "Content-Type": "application/json"
}
payload = {
    "query": "workout data from last week",
    "models": ["events", "blocks"],
    "threshold": 1.0,
    "limit": 20
}

response = requests.post(url, json=payload, headers=headers)
results = response.json()

for event in results["results"]["events"]:
    print(f"Event: {event['service']} - {event['action']}")
    print(f"  Similarity: {event['similarity']:.1%}")
    print(f"  URL: {event['url']}")
```

**Use Cases:**

- **External dashboards** displaying Spark data with semantic search
- **Mobile apps** querying user's Spark data
- **Browser extensions** searching across integrations
- **CLI tools** for power users
- **Automation scripts** finding relevant events/blocks
- **Analytics platforms** integrating Spark data

### Internal Search APIs

The original search endpoints are still available for internal use:

#### Search Events

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

// Semantic search (basic)
$embedding = app(\App\Services\EmbeddingService::class)->embed("payment issues");
$events = Event::semanticSearch($embedding, threshold: 1.0, limit: 20)->get();

// Semantic search with temporal weighting
// Recent events get a boost: temporalWeight of 0.01 = 1% penalty per day old
// Example: 7 days ago = 7% lower ranking, today = no penalty
$events = Event::semanticSearch(
    $embedding,
    threshold: 1.0,
    limit: 20,
    temporalWeight: 0.01  // Default: 0.01 (1% per day)
)->get();

// Disable temporal weighting (pure semantic similarity)
$events = Event::semanticSearch(
    $embedding,
    threshold: 1.0,
    limit: 20,
    temporalWeight: 0  // No recency boost
)->get();

// Hybrid search (semantic + filters + temporal weighting)
$events = Event::hybridSearch(
    $embedding,
    filters: [
        'service' => 'stripe',
        'domain' => 'payment',
        'from_date' => '2025-11-01'
    ],
    threshold: 1.0,
    limit: 20,
    temporalWeight: 0.015  // Stronger recency bias (1.5% per day)
)->get();

// Get searchable text
$text = $event->getSearchableText();
// Returns: "stripe payment failed 5000 cents"

// Access temporal data (when temporalWeight > 0)
foreach ($events as $event) {
    echo $event->similarity;           // Raw cosine distance (0-2)
    echo $event->days_ago;             // Days since event occurred
    echo $event->weighted_similarity;  // Adjusted score with temporal boost
}
```

### Block Model

```php
use App\Models\Block;

// Semantic search with temporal weighting
$blocks = Block::semanticSearch(
    $embedding,
    threshold: 1.0,
    limit: 20,
    temporalWeight: 0.01  // Default: 1% boost per day recent
)->get();

// Hybrid search with temporal weighting
$blocks = Block::hybridSearch(
    $embedding,
    filters: ['block_type' => 'fitness'],
    threshold: 1.0,
    limit: 20,
    temporalWeight: 0.02  // Strong recency bias (2% per day)
)->get();

// Get searchable text
$text = $block->getSearchableText();
// Returns: "Heart Rate Monitor 75 bpm https://example.com"
```

### Temporal Weighting Explained

Temporal weighting biases search results toward recent events:

**Formula:** `weighted_similarity = similarity * (1 + (days_ago * temporal_weight))`

**Examples:**

| Days Ago | Weight 0.01 | Weight 0.015 | Weight 0.02 |
|----------|-------------|--------------|-------------|
| Today    | 0% penalty  | 0% penalty   | 0% penalty  |
| 1 day    | 1% penalty  | 1.5% penalty | 2% penalty  |
| 7 days   | 7% penalty  | 10.5% penalty| 14% penalty |
| 30 days  | 30% penalty | 45% penalty  | 60% penalty |

**When to use:**

- **0.01** (default): Subtle recency bias for general queries
- **0.015**: Moderate bias when recent events are more relevant
- **0.02+**: Strong bias for time-sensitive searches
- **0**: No temporal bias (pure semantic similarity)

**Note:** Temporal weighting only applies when `time` field is present and not null.

## Advanced Features

### Batch Re-embedding Command

The `embeddings:regenerate` command provides advanced batch re-embedding capabilities with filtering, progress tracking, and forced regeneration.

#### Basic Usage

```bash
# Regenerate all embeddings for all models
php artisan embeddings:regenerate

# Regenerate only Events
php artisan embeddings:regenerate --model=Event

# Regenerate only Blocks
php artisan embeddings:regenerate --model=Block

# Regenerate only EventObjects
php artisan embeddings:regenerate --model=EventObject
```

#### Advanced Options

```bash
# Force regenerate even if embeddings already exist
php artisan embeddings:regenerate --force

# Filter by service
php artisan embeddings:regenerate --filter=service:fetch

# Filter by domain
php artisan embeddings:regenerate --filter=domain:health

# Limit number of records to process
php artisan embeddings:regenerate --limit=1000

# Run synchronously (for testing/debugging)
php artisan embeddings:regenerate --sync

# Combine options
php artisan embeddings:regenerate --model=Event --filter=service:monzo --force --limit=500
```

#### Progress Tracking

The command provides real-time progress tracking via:

- **Terminal progress bar** showing current progress
- **ActionProgress model** for tracking in database
- **Final summary** with total records processed

Example output:

```
Regenerating embeddings for Event...
Finding records to process...
Found 1,523 records to process

Processing...
 1523/1523 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

✓ Processed 1,523 Event records
```

#### Use Cases

**After model upgrade:**
```bash
# Update .env with new model
# OPENAI_EMBEDDING_MODEL=text-embedding-3-large

# Regenerate all embeddings with new model
php artisan embeddings:regenerate --force
```

**Fixing missing embeddings:**
```bash
# Only process records without embeddings
php artisan embeddings:regenerate
```

**Service-specific updates:**
```bash
# Regenerate only Monzo events
php artisan embeddings:regenerate --model=Event --filter=service:monzo --force
```

**Testing before full run:**
```bash
# Test with small batch first
php artisan embeddings:regenerate --limit=100 --sync
```

### Duplicate Detection

The duplicate detection system uses semantic similarity to identify potential duplicate content across Events, Blocks, and EventObjects.

#### Web UI

Access the duplicate detection interface at `/admin/duplicates`:

1. **Select Model Type:** Event, Block, or EventObject
2. **Set Similarity Threshold:** 0.80 (80%) to 0.99 (99%) - default 0.95
3. **Set Result Limit:** 10-200 results
4. **Click "Search"** to find duplicates

The UI displays:
- Similarity percentage for each duplicate pair
- Links to both items in the pair
- Preview of titles and content
- Color-coded similarity scores (high similarity = red badge)

#### Programmatic Usage

Use the `DuplicateDetectionService` in your code:

```php
use App\Services\DuplicateDetectionService;

$service = app(DuplicateDetectionService::class);
$userId = auth()->id();

// Find duplicate events (95% similar or higher)
$duplicates = $service->findDuplicateEvents($userId, similarityThreshold: 0.95, limit: 100);

// Find duplicate blocks (98% similar or higher)
$duplicates = $service->findDuplicateBlocks($userId, similarityThreshold: 0.98, limit: 50);

// Find duplicate objects (90% similar or higher)
$duplicates = $service->findDuplicateObjects($userId, similarityThreshold: 0.90, limit: 100);

// Process results
foreach ($duplicates as $duplicate) {
    echo "Found duplicate pair:\n";
    echo "  Item 1: {$duplicate['id1']}\n";
    echo "  Item 2: {$duplicate['id2']}\n";
    echo "  Similarity: " . round($duplicate['similarity'] * 100, 1) . "%\n";

    // Access full models
    $item1 = $duplicate['model1'];
    $item2 = $duplicate['model2'];
}
```

#### How It Works

The service uses PostgreSQL vector similarity with self-joins:

```sql
SELECT
    t1.id as id1,
    t2.id as id2,
    1 - (t1.embeddings <=> t2.embeddings) as similarity
FROM events t1
JOIN events t2 ON t1.id < t2.id
WHERE (t1.embeddings <=> t2.embeddings) < 0.05  -- 95% threshold
  AND t1.embeddings IS NOT NULL
  AND t2.embeddings IS NOT NULL
ORDER BY similarity DESC
LIMIT 100
```

**Performance:** Uses HNSW indexes for efficient similarity search even with large datasets.

#### Common Thresholds

| Threshold | Use Case |
|-----------|----------|
| 0.99 (99%) | Find near-exact duplicates (typos, minor differences) |
| 0.95 (95%) | Standard duplicate detection (recommended) |
| 0.90 (90%) | Find similar but not identical content |
| 0.85 (85%) | Broader similarity matching |
| 0.80 (80%) | Very permissive (may include false positives) |

### Embedding Health Dashboard

Monitor embedding coverage and health at `/admin/sense-check` (scroll to "Embedding Health" section).

#### Metrics Displayed

**Overall Statistics:**
- Total records across all models
- Records with embeddings
- Overall coverage percentage
- Color-coded indicators (🟢 >90%, 🟡 70-90%, 🔴 <70%)

**Per-Model Breakdown:**
- Events: total, with embeddings, coverage %
- Blocks: total, with embeddings, coverage %
- EventObjects: total, with embeddings, coverage %

**Coverage by Service (Top 10):**
- Service name
- Total events/blocks/objects
- Count with embeddings
- Coverage percentage
- Visual progress bars

**Coverage by Domain (Top 10):**
- Domain name
- Total events
- Count with embeddings
- Coverage percentage
- Visual progress bars

#### Example Output

```
Overall Coverage: 87.3% (🟡)
  Total Records: 15,234
  With Embeddings: 13,299

Events: 92.1% (🟢)
  Total: 8,456
  With Embeddings: 7,789

Blocks: 88.4% (🟡)
  Total: 4,321
  With Embeddings: 3,821

EventObjects: 74.2% (🟡)
  Total: 2,457
  With Embeddings: 1,823

Top Services by Volume:
  monzo: 3,245 events (98.5% coverage) ▓▓▓▓▓▓▓▓▓▓
  oura: 2,134 events (95.2% coverage) ▓▓▓▓▓▓▓▓▓░
  spotify: 1,876 events (89.3% coverage) ▓▓▓▓▓▓▓▓░░
```

#### Use Cases

**Identify coverage gaps:**
- Find services/domains with low embedding coverage
- Prioritize re-embedding efforts
- Monitor progress after running `embeddings:regenerate`

**Track embedding health over time:**
- Check coverage after bulk imports
- Verify new integrations are generating embeddings
- Ensure API key is configured correctly

**Debugging missing embeddings:**
- If a service shows 0% coverage, check if API key is configured
- If coverage is partial, check job failures: `php artisan queue:failed`
- If coverage is decreasing, check observer configuration

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

## Testing

### Factory Configuration

Model factories are configured to work with or without embeddings:

```php
use App\Models\Event;
use App\Models\Block;
use App\Models\EventObject;

// Create without embeddings (default, fast)
$event = Event::factory()->create();
// embeddings field will be null

// Create with embeddings (when needed for tests)
$event = Event::factory()->withEmbeddings()->create();
// embeddings field will contain 1536-dimension vector

// Same pattern for all models
$block = Block::factory()->withEmbeddings()->create();
$object = EventObject::factory()->withEmbeddings()->create();
```

**Why this matters:**
- Generating 1536-dimension vectors in tests is slow
- Most tests don't need actual embeddings
- Using `null` by default speeds up test suite significantly
- Use `withEmbeddings()` only when testing semantic search features

### Observer Behavior in Tests

Model observers check for OpenAI API key before dispatching jobs:

```php
// In EventObserver, BlockObserver, EventObjectObserver
public function created(Event $event): void
{
    // Only dispatch if embeddings are enabled (API key is configured)
    if (config('services.openai.api_key')) {
        GenerateEventEmbeddingJob::dispatch($event);
    }
}
```

**Benefits:**
- Tests run without `OPENAI_API_KEY` configured
- No job dispatch errors in test environment
- No need to mock embedding service in most tests
- Prevents accidental API calls during testing

### Testing Semantic Search Features

When testing semantic search functionality:

```php
use Tests\TestCase;
use App\Models\Event;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_search_finds_similar_events()
    {
        // Create events with embeddings
        $event1 = Event::factory()->withEmbeddings()->create([
            'service' => 'test',
            'action' => 'payment_success'
        ]);

        $event2 = Event::factory()->withEmbeddings()->create([
            'service' => 'test',
            'action' => 'payment_failed'
        ]);

        // Mock embedding service
        $this->mock(EmbeddingService::class)
            ->shouldReceive('embed')
            ->andReturn($event1->embeddings);

        // Test search
        $embedding = app(EmbeddingService::class)->embed('payment');
        $results = Event::semanticSearch($embedding, threshold: 1.0)->get();

        $this->assertCount(2, $results);
    }
}
```

### Failed Job Handling

Embedding jobs now accept `Throwable` instead of just `Exception`:

```php
public function failed(?\Throwable $exception): void
{
    Log::error('GenerateEventEmbeddingJob failed', [
        'event_id' => $this->event->id,
        'error' => $exception?->getMessage(),
    ]);
}
```

This handles both `Exception` and `Error` types (e.g., `TypeError`), ensuring robust error handling in production and tests.

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

1. ✅ **Hybrid ranking** - ~~Combine semantic similarity with metadata relevance~~ (Implemented via `hybridSearch()` method)
2. ✅ **Entity search** - ~~Extend to EventObjects~~ (Implemented with EventObjectObserver and semantic search)
3. ✅ **Duplicate detection** - ~~Identify duplicate content~~ (Implemented via DuplicateDetectionService)
4. ✅ **External API** - ~~API for third-party tools~~ (Implemented via SemanticSearchController)
5. ✅ **Embedding versioning** - ~~Track model versions~~ (Implemented via metadata storage)
6. ✅ **Health monitoring** - ~~Coverage dashboard~~ (Implemented in sense-check page)
7. **Re-ranking** - Use more powerful models for top-K results
8. **Advanced filters UI** - Rich search interface with faceted filtering in Livewire
9. **Multi-modal search** - Include image/video content in embeddings
10. **Local models** - Self-hosted embeddings for privacy (e.g., sentence-transformers)
11. **Fine-tuning** - Domain-specific embedding models trained on user's data
12. **Semantic clustering** - Group similar events/blocks automatically
13. **Smart recommendations** - "Similar to this event" suggestions
14. **Cross-model relationships** - Find related events, blocks, and objects in one query

## References

- [OpenAI Embeddings Guide](https://platform.openai.com/docs/guides/embeddings)
- [pgvector Documentation](https://github.com/pgvector/pgvector)
- [PostgreSQL Vector Operations](https://github.com/pgvector/pgvector#querying)
