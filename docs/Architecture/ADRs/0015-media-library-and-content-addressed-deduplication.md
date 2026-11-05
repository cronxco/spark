# ADR 0015: Media Library and Content-Addressed Deduplication

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Spatie Media Library and `MediaDownloadHelper` store configured media and deduplicate by MD5.

## Decision
Spark retains its shared library/helper-based MD5-only, best-effort media deduplication. Collision-resistant content identity is not a current product requirement; intentional-collision and integrity risk are explicitly accepted.

## Consequences
Repeated content can be reused. Media lifecycle and access control remain required policy work; cryptographic identity, rehashing, and storage migration are not implied.

## Alternatives rejected
SHA-256 canonical identity and hybrid checksum verification were rejected for now.

## Related repository paths
`app/Services/Media/`, `config/filesystems.php`, `config/media-library.php`, `docs/Architecture/MEDIA.md`.

## Evidence gaps / open questions
Define media retention and access-control policy; reassess integrity controls if threat model or product needs change.
