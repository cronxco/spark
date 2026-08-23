# ADR 0014: pgvector Semantic Search

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
1536-dimensional vectors and HNSW cosine indexes support asynchronous semantic search.

## Decision
Semantic-search embeddings are rebuildable derived data. They remain tied to source records, are removed with source deletion, and source/model changes trigger controlled re-embedding with the active model/version recorded.

## Consequences
Embedding work is queued. Model/provider lifecycle now requires controlled re-embedding and recorded active model/version; provider contracts and detailed migration operations remain open.

## Alternatives rejected
Indefinite implementation-artifact retention and transient-only semantic search were rejected.

## Related repository paths
`database/migrations/2022_08_03_000000_create_vector_extension.php`, `database/migrations/2025_11_15_000001_update_embeddings_to_vector_type.php`, `database/migrations/2025_11_15_000002_add_vector_indexes.php`, `docs/Architecture/SEMANTIC_SEARCH.md`.

## Evidence gaps / open questions
Define controlled re-embedding, indexing rollout/rollback, and provider-change operations.
