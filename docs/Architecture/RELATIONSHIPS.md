# Relationships

Relationships create typed, directional or bi-directional connections between any model types in Spark.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
    - [Database Schema](#database-schema)
    - [Key Attributes](#key-attributes)
    - [Relationships (Model Relations)](#relationships-model-relations)
    - [Unique Constraints](#unique-constraints)
    - [Indexes](#indexes)
- [Relationship Types](#relationship-types)
    - [Directional vs Bi-Directional](#directional-vs-bi-directional)
    - [Available Types](#available-types)
- [Monetary Relationships](#monetary-relationships)
- [Pending Relationship System](#pending-relationship-system)
    - [Overview](#pending-overview)
    - [Metadata Structure](#pending-metadata-structure)
    - [Workflow](#pending-workflow)
    - [Use Cases](#pending-use-cases)
- [Key Methods](#key-methods)
    - [Creating Relationships](#creating-relationships)
    - [Finding Relationships](#finding-relationships)
    - [Value Formatting](#value-formatting)
    - [Directional Checks](#directional-checks)
    - [Pending/Confirmed Methods](#pendingconfirmed-methods)
    - [Scopes](#scopes)
- [Usage Examples](#usage-examples)
    - [Creating Directional Relationships](#creating-directional-relationships)
    - [Creating Bi-Directional Relationships](#creating-bi-directional-relationships)
    - [Monetary Transfers](#monetary-transfers)
    - [Pending Relationships Workflow](#pending-relationships-workflow)
    - [Querying Relationships](#querying-relationships)
    - [Getting Related Entities](#getting-related-entities)
    - [Polymorphic Relationship Patterns](#polymorphic-relationship-patterns)
- [Common Patterns](#common-patterns)
    - [Old had_link_to Migration](#old-had_link_to-migration)
    - [Avoiding Duplicate Relationships](#avoiding-duplicate-relationships)
- [Related Documentation](#related-documentation)

## Overview

Relationships create typed connections between any model types (Events, EventObjects, Blocks) in Spark. They support:

- **Polymorphic linking** - Connect any model to any other model
- **Directional and bi-directional types** - One-way or two-way relationships
- **Monetary tracking** - Store value, value_multiplier, value_unit for financial relationships
- **Pending workflow** - AI-detected relationships that await user approval
- **User-scoped** - All relationships belong to a specific user

**Example use cases:**

- Event occurred at Place (directional)
- Event linked to webpage (directional)
- Event caused by another Event (directional)
- Event related to another Event (bi-directional)
- Account transferred money to Account (directional with value)

## Architecture

### Database Schema

**Table:** `relationships`

**Primary Key:** `id` (UUID)

Relationships are soft-deletable and support activity logging.

### Key Attributes

| Attribute          | Type       | Description                                          |
| ------------------ | ---------- | ---------------------------------------------------- |
| `id`               | UUID       | Primary key, auto-generated                          |
| `user_id`          | UUID       | Foreign key to User (ownership)                      |
| `from_type`        | string     | Polymorphic model class (Event, EventObject, Block)  |
| `from_id`          | UUID       | ID of "from" entity                                  |
| `to_type`          | string     | Polymorphic model class                              |
| `to_id`            | UUID       | ID of "to" entity                                    |
| `type`             | string     | Relationship type (linked_to, related_to, etc.)      |
| `value`            | bigInteger | Optional monetary/numeric value (nullable)           |
| `value_multiplier` | integer    | Divider for value (default 1)                        |
| `value_unit`       | string     | Unit (GBP, USD, etc.) (nullable)                     |
| `metadata`         | JSON       | Extra data (pending, confidence, detection_strategy) |
| `created_at`       | timestamp  | When record was created                              |
| `updated_at`       | timestamp  | When record was last updated                         |
| `deleted_at`       | timestamp  | When record was soft-deleted (nullable)              |

### Relationships (Model Relations)

- `from()` - MorphTo - Polymorphic "from" entity
- `to()` - MorphTo - Polymorphic "to" entity
- `user()` - BelongsTo User

### Unique Constraints

- `(user_id, from_type, from_id, to_type, to_id, type)` - Prevents duplicate relationships

This constraint ensures that the same relationship between two entities cannot be created twice.

### Indexes

- Index on `(from_type, from_id)` - For querying "from" entities
- Index on `(to_type, to_id)` - For querying "to" entities
- Index on `type` - For filtering by relationship type
- Index on `user_id` - For filtering by user

## Relationship Types

Relationship types are defined in `app/Services/RelationshipTypeRegistry.php`.

### Directional vs Bi-Directional

**Directional** - One-way relationship (A → B):

- `linked_to` - A links to B
- `caused_by` - A was caused by B
- `part_of` - A is part of B
- `transferred_to` - A transferred value to B
- `occurred_at` - Event occurred at Place

**Bi-Directional** - Two-way relationship (A ↔ B):

- `related_to` - A is related to B (and B is related to A)
- `similar_to` - A is similar to B (and B is similar to A)

Bi-directional relationships automatically prevent creating duplicate reverse relationships.

### Available Types

| Type             | Direction      | Description               | Supports Value |
| ---------------- | -------------- | ------------------------- | -------------- |
| `linked_to`      | Directional    | Source links to target    | No             |
| `related_to`     | Bi-directional | General association       | No             |
| `caused_by`      | Directional    | Causal relationship       | No             |
| `part_of`        | Directional    | Hierarchical relationship | No             |
| `similar_to`     | Bi-directional | Similarity relationship   | No             |
| `transferred_to` | Directional    | Money/value transfer      | Yes            |
| `occurred_at`    | Directional    | Event at Place            | No             |

## Monetary Relationships

The `transferred_to` type supports storing monetary values:

```php
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => EventObject::class,
    'from_id' => $fromAccount->id,
    'to_type' => EventObject::class,
    'to_id' => $toAccount->id,
    'type' => 'transferred_to',
    'value' => 10000, // £100.00 in pence
    'value_multiplier' => 100,
    'value_unit' => 'GBP',
]);
```

**Formatted value:**

```php
$formatted = $relationship->formatted_value; // 100.00
echo "£{$formatted}"; // £100.00
```

## Pending Relationship System

### Pending Overview

Relationships can be marked as "pending" when detected by AI or automated systems, requiring user approval before being fully confirmed.

### Pending Metadata Structure

```php
$relationship->metadata = [
    'pending' => true,
    'confidence' => 0.85, // 0.0 - 1.0
    'detection_strategy' => 'semantic_similarity',
    'matching_criteria' => [
        'similarity_score' => 0.85,
        'shared_keywords' => ['coffee', 'meeting'],
    ],
    'detected_at' => '2025-01-10T12:00:00Z',
    'approved_at' => null, // Set when approved
    'rejected_at' => null, // Set when rejected
];
```

### Pending Workflow

1. **AI Detection** - System detects potential relationship
2. **Create Pending** - Relationship created with `pending: true` in metadata
3. **User Review** - User sees pending relationships in UI
4. **User Action**:
    - **Approve** - Call `approve()`, sets `approved_at` in metadata
    - **Reject** - Call `reject()`, soft-deletes with `rejected_at` in metadata

### Pending Use Cases

- **Automatic relationship detection** - AI finds connections between events
- **Receipt matching** - Link transaction events to receipt images
- **Duplicate detection** - Suggest merging similar events
- **Cross-integration linking** - Connect Spotify plays to calendar events

## Key Methods

### Creating Relationships

**`static createRelationship(array $attributes): Relationship`**

Creates a relationship with bi-directional awareness. For bi-directional types, prevents creating duplicate reverse relationships.

```php
// Location: app/Models/Relationship.php

$relationship = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event1->id,
    'to_type' => Event::class,
    'to_id' => $event2->id,
    'type' => 'related_to', // Bi-directional
]);

// If called again with reversed from/to, returns existing relationship
$sameRelationship = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event2->id, // Swapped
    'to_type' => Event::class,
    'to_id' => $event1->id, // Swapped
    'type' => 'related_to',
]);
// Returns the same relationship (no duplicate created)
```

### Finding Relationships

**`static findOrCreateRelationship(array $attributes, array $values = []): Relationship`**

FirstOrCreate with bi-directional awareness.

```php
$relationship = Relationship::findOrCreateRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $place->id,
    'type' => 'occurred_at',
], [
    'metadata' => ['source' => 'place_detection'],
]);
```

### Value Formatting

**`getFormattedValueAttribute(): ?float`**

Returns the value divided by the value_multiplier.

```php
$relationship->value = 1250;
$relationship->value_multiplier = 100;

$formatted = $relationship->formatted_value; // 12.50
```

### Directional Checks

**`isDirectional(): bool`**

Checks if the relationship type is directional.

```php
$relationship->type = 'linked_to';
$relationship->isDirectional(); // true

$relationship->type = 'related_to';
$relationship->isDirectional(); // false
```

**`getTypeConfig(): array`**

Gets configuration from RelationshipTypeRegistry.

```php
$config = $relationship->getTypeConfig();
// [
//     'name' => 'linked_to',
//     'label' => 'Linked To',
//     'directional' => true,
// ]
```

**`getOpposite(): ?Relationship`**

For bi-directional types, finds the reverse relationship.

```php
// For bi-directional relationships only
$opposite = $relationship->getOpposite();
```

### Pending/Confirmed Methods

**`isPending(): bool`**

Checks if relationship is pending approval.

```php
if ($relationship->isPending()) {
    // Show approval UI
}
```

**`isConfirmed(): bool`**

Checks if relationship is confirmed (not pending).

```php
if ($relationship->isConfirmed()) {
    // Display normally
}
```

**`getConfidence(): ?float`**

Gets confidence score from metadata.

```php
$confidence = $relationship->getConfidence();
// Returns: 0.85 (or null if not set)
```

**`getDetectionStrategy(): ?string`**

Gets detection strategy from metadata.

```php
$strategy = $relationship->getDetectionStrategy();
// Returns: 'semantic_similarity'
```

**`getMatchingCriteria(): ?array`**

Gets matching algorithm info from metadata.

```php
$criteria = $relationship->getMatchingCriteria();
// Returns: ['similarity_score' => 0.85, ...]
```

**`approve(): void`**

Marks relationship as confirmed.

```php
$relationship->approve();
// Sets metadata['approved_at'] = now()
// Sets metadata['pending'] = false
```

**`reject(): void`**

Soft-deletes relationship with rejection timestamp.

```php
$relationship->reject();
// Sets metadata['rejected_at'] = now()
// Soft-deletes the relationship
```

### Scopes

**`scopePending($query)`**

Filter to pending relationships.

```php
$pending = Relationship::pending()->get();
```

**`scopeConfirmed($query)`**

Filter to confirmed relationships.

```php
$confirmed = Relationship::confirmed()->get();
```

**`scopeAboveConfidence($query, float $threshold)`**

Filter by minimum confidence score.

```php
$highConfidence = Relationship::pending()
    ->aboveConfidence(0.80)
    ->get();
```

**`scopeForStrategy($query, string $strategy)`**

Filter by detection strategy.

```php
$semanticRelationships = Relationship::pending()
    ->forStrategy('semantic_similarity')
    ->get();
```

**`scopeBetweenEvents($query, string $eventAId, string $eventBId)`**

Find relationships between two events (either direction).

```php
$relationships = Relationship::betweenEvents($event1->id, $event2->id)->get();
```

## Usage Examples

### Creating Directional Relationships

**Event to Event:**

```php
// Event A caused Event B
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $eventA->id,
    'to_type' => Event::class,
    'to_id' => $eventB->id,
    'type' => 'caused_by',
]);
```

**Event to Object:**

```php
// Event occurred at Place
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $place->id,
    'type' => 'occurred_at',
]);
```

**Event to Event (link):**

```php
// Event linked to webpage
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $webpage->id,
    'type' => 'linked_to',
]);
```

### Creating Bi-Directional Relationships

**Auto-deduplication explained:**

```php
// Create relationship A → B
$rel1 = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $eventA->id,
    'to_type' => Event::class,
    'to_id' => $eventB->id,
    'type' => 'related_to', // Bi-directional
]);

// Try to create relationship B → A (reverse)
$rel2 = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $eventB->id, // Swapped
    'to_type' => Event::class,
    'to_id' => $eventA->id, // Swapped
    'type' => 'related_to',
]);

// $rel1->id === $rel2->id (same relationship returned)
// No duplicate created!
```

### Monetary Transfers

**Real-world example from Monzo:**

```php
// Location: app/Jobs/Data/Monzo/MonzoTransferData.php

// Account A transferred £100 to Account B
Relationship::createRelationship([
    'user_id' => $integration->user_id,
    'from_type' => EventObject::class,
    'from_id' => $fromAccount->id,
    'to_type' => EventObject::class,
    'to_id' => $toAccount->id,
    'type' => 'transferred_to',
    'value' => 10000, // £100.00 in pence
    'value_multiplier' => 100,
    'value_unit' => 'GBP',
    'metadata' => [
        'transaction_id' => $transfer['id'],
        'transfer_date' => $transfer['created'],
    ],
]);
```

### Pending Relationships Workflow

**AI detection → pending → approval:**

```php
// Step 1: AI detects potential relationship
$similarity = calculateSimilarity($event1, $event2); // 0.85

// Step 2: Create pending relationship
$relationship = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event1->id,
    'to_type' => Event::class,
    'to_id' => $event2->id,
    'type' => 'related_to',
    'metadata' => [
        'pending' => true,
        'confidence' => $similarity,
        'detection_strategy' => 'semantic_similarity',
        'matching_criteria' => [
            'similarity_score' => $similarity,
            'shared_keywords' => ['meeting', 'coffee'],
        ],
        'detected_at' => now()->toISOString(),
    ],
]);

// Step 3: User reviews
if ($relationship->isPending() && $relationship->getConfidence() > 0.80) {
    // Show to user with confidence indicator
}

// Step 4a: User approves
$relationship->approve();

// OR Step 4b: User rejects
$relationship->reject();
```

### Querying Relationships

**All relationships for an event:**

```php
$event->relationshipsFrom()->get(); // Where event is "from"
$event->relationshipsTo()->get();   // Where event is "to"
$event->allRelationships()->get();  // All relationships
```

**Filter by type:**

```php
$event->relationshipsFrom()
    ->where('type', 'linked_to')
    ->get();
```

**Pending relationships for user:**

```php
$pending = Relationship::where('user_id', $user->id)
    ->pending()
    ->aboveConfidence(0.75)
    ->get();
```

**High confidence pending relationships:**

```php
$highConfidence = Relationship::pending()
    ->aboveConfidence(0.90)
    ->forStrategy('semantic_similarity')
    ->get();
```

### Getting Related Entities

**Via model methods:**

```php
// Get related objects
$objects = $event->relatedObjects()->get();

// Get related objects of specific type
$places = $event->relatedObjects('occurred_at')->get();

// Get related events
$relatedEvents = $event->relatedEvents()->get();

// Get related blocks
$relatedBlocks = $event->relatedBlocks()->get();
```

### Polymorphic Relationship Patterns

**Event to EventObject:**

```php
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
]);
```

**EventObject to EventObject:**

```php
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => EventObject::class,
    'from_id' => $object1->id,
    'to_type' => EventObject::class,
    'to_id' => $object2->id,
    'type' => 'related_to',
]);
```

**Event to Block:**

```php
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => Block::class,
    'to_id' => $block->id,
    'type' => 'part_of',
]);
```

**Block to EventObject:**

```php
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Block::class,
    'from_id' => $block->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
]);
```

## Common Patterns

### Old had_link_to Migration

The old `had_link_to` event action has been migrated to the Relationship model.

**Old pattern (deprecated):**

```php
// ❌ Old way - creating event with action 'had_link_to'
Event::create([
    'action' => 'had_link_to',
    'actor_id' => $event->id,
    'target_id' => $webpage->id,
    'service' => 'fetch',
    // ...
]);
```

**New pattern (correct):**

```php
// ✓ New way - using Relationship model
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $webpage->id,
    'type' => 'linked_to',
]);
```

### Avoiding Duplicate Relationships

**Always use createRelationship:**

```php
// CORRECT - Uses createRelationship
$rel = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
]);

// Calling again returns existing relationship (no duplicate)
$sameRel = Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
]);
// $rel->id === $sameRel->id
```

**INCORRECT - Direct create:**

```php
// ❌ INCORRECT - Creates duplicates
Relationship::create([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
]);

// This creates a SECOND relationship!
Relationship::create([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
]);
```

**Check before creating:**

```php
// Alternative: Check first, then create
$existing = Relationship::where([
    'user_id' => $user->id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $object->id,
    'type' => 'linked_to',
])->first();

if (!$existing) {
    $relationship = Relationship::createRelationship([...]);
}

// But createRelationship handles this automatically!
```

## Related Documentation

- [EVENTS.md](EVENTS.md) - Event model (relationship endpoints)
- [OBJECTS.md](OBJECTS.md) - EventObject model (relationship endpoints)
- [BLOCKS.md](BLOCKS.md) - Block model (relationship endpoints)
- [PLACES.md](PLACES.md) - Place relationships (occurred_at)
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - How integrations create relationships
- [JOBS.md](JOBS.md) - Job architecture for relationship creation
- [../CLAUDE.md](../CLAUDE.md) - Development guide
