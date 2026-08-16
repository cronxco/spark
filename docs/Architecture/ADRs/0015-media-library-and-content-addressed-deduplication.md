# ADR 0015: Media Library and Content-Addressed Deduplication

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Spatie Media Library and `MediaDownloadHelper` store configured media and deduplicate by MD5.

## Decision
The current media path uses the shared library and helper-based MD5 deduplication.

## Consequences
Repeated content can be reused; media lifecycle, access control, and integrity requirements remain open.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`app/Services/Media/`, `config/filesystems.php`, `config/media-library.php`, `docs/Architecture/MEDIA.md`.

## Evidence gaps / open questions
Define media retention and stronger integrity needs.
