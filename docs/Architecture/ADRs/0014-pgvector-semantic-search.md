# ADR 0014: pgvector Semantic Search

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
1536-dimensional vectors and HNSW cosine indexes support asynchronous semantic search.

## Decision
The current semantic-search implementation uses pgvector and asynchronous embedding generation.

## Consequences
Embedding work is queued; provider/model changes, replay, and extension compatibility require operations policy.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`database/migrations/2022_08_03_000000_create_vector_extension.php`, `database/migrations/2025_11_15_000001_update_embeddings_to_vector_type.php`, `2025_11_15_000002_add_vector_indexes.php`, `docs/Architecture/SEMANTIC_SEARCH.md`.

## Evidence gaps / open questions
Define re-indexing and rollback policy.
