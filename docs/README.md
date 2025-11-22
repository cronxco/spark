# Spark Documentation

Welcome to the Spark documentation. This directory contains comprehensive documentation for the Spark integration platform.

## Quick Links

- [CLAUDE.md](../CLAUDE.md) - Complete development guide and architecture overview
- [WARP.md](../WARP.md) - Quick start guide for new developers
- [Style Guide](STYLE_GUIDE.md) - Documentation standards and templates

## Documentation Index

### Getting Started

| Document | Description |
|----------|-------------|
| [CLAUDE.md](../CLAUDE.md) | Development commands, architecture, code style, and conventions |
| [WARP.md](../WARP.md) | Quick start guide with architecture diagrams |
| [STYLE_GUIDE.md](STYLE_GUIDE.md) | Documentation standards and templates |

### Core Systems

| Document | Description |
|----------|-------------|
| [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) | Plugin architecture, base classes, and implementation patterns |
| [README_JOBS.md](README_JOBS.md) | Job system overview and base job classes |
| [API_DOCUMENTATION.md](API_DOCUMENTATION.md) | REST API endpoints and authentication |
| [API_QUICK_REFERENCE.md](API_QUICK_REFERENCE.md) | Quick reference for API usage |

### Features

| Document | Description |
|----------|-------------|
| [SPOTLIGHT.md](SPOTLIGHT.md) | Command palette search system |
| [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) | Vector embedding search with pgvector |
| [CARD_STREAMS.md](CARD_STREAMS.md) | Instagram Stories-like card UI system |
| [DESIGN_PATTERNS.md](DESIGN_PATTERNS.md) | UI consistency and visual hierarchy |
| [NOTIFICATIONS.md](NOTIFICATIONS.md) | Notification system |
| [TAGS_USAGE.md](TAGS_USAGE.md) | Tag system usage |

### Integrations

#### Health Domain

| Document | Integration | Type | Status |
|----------|-------------|------|--------|
| [APPLE_HEALTH_INTEGRATION.md](APPLE_HEALTH_INTEGRATION.md) | Apple Health | Webhook | Documented |
| [OURA_INTEGRATION.md](OURA_INTEGRATION.md) | Oura Ring | OAuth | Documented |
| [HEVY_INTEGRATION.md](HEVY_INTEGRATION.md) | Hevy Workout | API Key | Documented |
| [GOOGLE_CALENDAR_INTEGRATION.md](GOOGLE_CALENDAR_INTEGRATION.md) | Google Calendar | OAuth | Documented |

#### Finance Domain

| Document | Integration | Type | Status |
|----------|-------------|------|--------|
| [INTEGRATIONS_MONZO.md](INTEGRATIONS_MONZO.md) | Monzo | OAuth | Documented |
| [GOCARDLESS_INTEGRATION.md](GOCARDLESS_INTEGRATION.md) | GoCardless | OAuth | Documented |
| [FINANCIAL_INTEGRATION_REFACTORED.md](FINANCIAL_INTEGRATION_REFACTORED.md) | Financial (Manual) | Manual | Documented |

#### Media Domain

| Document | Integration | Type | Status |
|----------|-------------|------|--------|
| [SPOTIFY_INTEGRATION.md](SPOTIFY_INTEGRATION.md) | Spotify | OAuth | Documented |
| [KARAKEEP_INTEGRATION.md](KARAKEEP_INTEGRATION.md) | Karakeep | API Key | Documented |

#### Knowledge Domain

| Document | Integration | Type | Status |
|----------|-------------|------|--------|
| [OUTLINE_INTEGRATION.md](OUTLINE_INTEGRATION.md) | Outline | API Key | Documented |

#### Online/Social Domain

| Document | Integration | Type | Status |
|----------|-------------|------|--------|
| [GITHUB_INTEGRATION.md](GITHUB_INTEGRATION.md) | GitHub | OAuth | Documented |
| [BLUESKY_INTEGRATION.md](BLUESKY_INTEGRATION.md) | BlueSky | OAuth | Documented |
| [REDDIT_INTEGRATION.md](REDDIT_INTEGRATION.md) | Reddit | OAuth | Documented |
| [SLACK_INTEGRATION.md](SLACK_INTEGRATION.md) | Slack | Webhook | Documented |
| [DAILYCHECKIN_INTEGRATION.md](DAILYCHECKIN_INTEGRATION.md) | Daily Check-in | Manual | Documented |
| [TASK_INTEGRATION.md](TASK_INTEGRATION.md) | Task Runner | Special | Documented |

#### Web Content Domain

| Document | Integration | Type | Status |
|----------|-------------|------|--------|
| [FETCH_INTEGRATION.md](FETCH_INTEGRATION.md) | Fetch (Web Scraping) | Manual | Documented |

### Technical Guides

| Document | Description |
|----------|-------------|
| [EVENTS_INTERFACE.md](EVENTS_INTERFACE.md) | Event data model and interface |
| [UPDATES_INTERFACE.md](UPDATES_INTERFACE.md) | Integration update system |
| [SCHEDULED_INTEGRATION_UPDATES.md](SCHEDULED_INTEGRATION_UPDATES.md) | Update scheduling system |
| [LONG_RUNNING_TASK_TRACKING.md](LONG_RUNNING_TASK_TRACKING.md) | ActionProgress model for tracking |
| [ACTION_PROGRESS_QUICK_REFERENCE.md](ACTION_PROGRESS_QUICK_REFERENCE.md) | Quick reference for progress tracking |
| [SOFT_DELETES.md](SOFT_DELETES.md) | Soft delete implementation |
| [BLOCK_CREATION_MIGRATION_GUIDE.md](BLOCK_CREATION_MIGRATION_GUIDE.md) | Migrating to `createBlock()` |

### Infrastructure

| Document | Description |
|----------|-------------|
| [aws-s3-setup.md](aws-s3-setup.md) | AWS S3 media storage configuration |

### QA & Testing

| Document | Description |
|----------|-------------|
| [TESTING.md](TESTING.md) | Testing guide and patterns |
| [SPOTLIGHT_TEST.md](SPOTLIGHT_TEST.md) | Spotlight feature testing checklist |

### Reference

| Document | Description |
|----------|-------------|
| [oura-api-v2-specification.md](oura-api-v2-specification.md) | Oura API v2 reference |
| [oura-plugin-refactoring-status.md](oura-plugin-refactoring-status.md) | Oura plugin refactoring notes |
| [metrics-future-enhancements.md](metrics-future-enhancements.md) | Metrics system roadmap |
| [JOB_REFACTORING.md](JOB_REFACTORING.md) | Job system migration notes |

## Documentation Standards

All documentation in this project follows the [Style Guide](STYLE_GUIDE.md). Key points:

- Use clear, concise language
- Include working code examples
- Follow the templates for integrations and features
- Keep documentation up-to-date with code changes

## Contributing to Documentation

1. Follow the [Style Guide](STYLE_GUIDE.md)
2. Use the appropriate template for your document type
3. Test all code examples
4. Update this index when adding new documents
5. Review the checklist in the style guide before committing

## Getting Help

- Check [CLAUDE.md](../CLAUDE.md) for development guidance
- Check [WARP.md](../WARP.md) for quick start information
- Search existing documentation for related topics
- Create an issue for documentation gaps or errors
