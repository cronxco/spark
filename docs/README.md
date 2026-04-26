# Spark Documentation

Welcome to the Spark documentation. This directory contains comprehensive documentation for the Spark integration platform.

## Quick Links

- [CLAUDE.md](../CLAUDE.md) - Complete development guide and architecture overview
- [DOCS_STYLE_GUIDE.md](DOCS_STYLE_GUIDE.md) - Documentation standards and templates

## Directory Structure

```
docs/
├── Architecture/      # Core system and feature documentation
├── Guides/           # Setup and operational guides
├── Integrations/     # Service-specific documentation
├── mobile/           # iOS companion app API
└── UI and UX/        # User interface patterns
```

## Documentation Index

### Getting Started

| Document                                   | Description                                                     |
| ------------------------------------------ | --------------------------------------------------------------- |
| [CLAUDE.md](../CLAUDE.md)                  | Development commands, architecture, code style, and conventions |
| [DOCS_STYLE_GUIDE.md](DOCS_STYLE_GUIDE.md) | Documentation standards and templates                           |

### Core Architecture

| Document                                          | Description                                                                      |
| ------------------------------------------------- | -------------------------------------------------------------------------------- |
| [EVENTS.md](Architecture/EVENTS.md)               | Timestamped data points with actor/target pattern, values, and location          |
| [OBJECTS.md](Architecture/OBJECTS.md)             | User-scoped entities that events reference (accounts, tracks, places, documents) |
| [BLOCKS.md](Architecture/BLOCKS.md)               | Aggregated visualizations and summaries with custom card layouts                 |
| [PLACES.md](Architecture/PLACES.md)               | Location tracking, PostGIS integration, and geocoding services                   |
| [RELATIONSHIPS.md](Architecture/RELATIONSHIPS.md) | Typed connections between models with pending workflow support                   |

### Core Systems

| Document                                                      | Description                                                    |
| ------------------------------------------------------------- | -------------------------------------------------------------- |
| [INTEGRATION_PLUGINS.md](Architecture/INTEGRATION_PLUGINS.md) | Plugin architecture, base classes, and implementation patterns |
| [JOBS.md](Architecture/JOBS.md)                               | Job system overview and base job classes                       |
| [API.md](Architecture/API.md)                                 | REST API endpoints and authentication                          |
| [MOBILE_API.md](mobile/MOBILE_API.md)                         | iOS companion app API — all 25 endpoints with schemas          |
| [MEDIA.md](Architecture/MEDIA.md)                             | Media attachment system with MD5-based deduplication           |
| [PLAYWRIGHT.md](Architecture/PLAYWRIGHT.md)                   | Browser automation for Fetch integration                       |

### Features

| Document                                                                              | Description                                                         |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| [SPOTLIGHT.md](Architecture/SPOTLIGHT.md)                                             | Command palette search system                                       |
| [SEMANTIC_SEARCH.md](Architecture/SEMANTIC_SEARCH.md)                                 | Vector embedding search with pgvector                               |
| [CARD_STREAMS.md](Architecture/CARD_STREAMS.md)                                       | Instagram Stories-like card UI system                               |
| [NOTIFICATIONS.md](Architecture/NOTIFICATIONS.md)                                     | Notification system                                                 |
| [TAGS.md](Architecture/TAGS.md)                                                       | Tag system usage                                                    |
| [TASK_PIPELINE.md](Architecture/TASK_PIPELINE.md)                                     | Event processing pipeline (embedding generation, anomaly detection) |
| [ACTION_PROGRESS.md](Architecture/ACTION_PROGRESS.md)                                 | Long-running task tracking                                          |
| [ACTION_PROGRESS_QUICK_REFERENCE.md](Architecture/ACTION_PROGRESS_QUICK_REFERENCE.md) | Quick reference for progress tracking                               |
| [SCHEDULED_INTEGRATION_UPDATES.md](Architecture/SCHEDULED_INTEGRATION_UPDATES.md)     | Update scheduling system                                            |
| [SOFT_DELETES.md](Architecture/SOFT_DELETES.md)                                       | Soft delete implementation                                          |
| [TESTING.md](Architecture/TESTING.md)                                                 | Testing guide and patterns                                          |

### Integrations

#### Health Domain

| Document                                                                      | Integration     | Type    | Description                             |
| ----------------------------------------------------------------------------- | --------------- | ------- | --------------------------------------- |
| [APPLE_HEALTH_INTEGRATION.md](Integrations/APPLE_HEALTH_INTEGRATION.md)       | Apple Health    | Webhook | Health metrics and workout data         |
| [OURA_INTEGRATION.md](Integrations/OURA_INTEGRATION.md)                       | Oura Ring       | OAuth   | Sleep, readiness, and activity tracking |
| [HEVY_INTEGRATION.md](Integrations/HEVY_INTEGRATION.md)                       | Hevy Workout    | API Key | Workout logging and tracking            |
| [GOOGLE_CALENDAR_INTEGRATION.md](Integrations/GOOGLE_CALENDAR_INTEGRATION.md) | Google Calendar | OAuth   | Calendar events with location geocoding |

#### Finance Domain

| Document                                                            | Integration        | Type   | Description                                  |
| ------------------------------------------------------------------- | ------------------ | ------ | -------------------------------------------- |
| [MONZO_INTEGRATION.md](Integrations/MONZO_INTEGRATION.md)           | Monzo              | OAuth  | Banking transactions with merchant locations |
| [GOCARDLESS_INTEGRATION.md](Integrations/GOCARDLESS_INTEGRATION.md) | GoCardless         | OAuth  | Payment management                           |
| [FINANCIAL_INTEGRATION.md](Integrations/FINANCIAL_INTEGRATION.md)   | Financial (Manual) | Manual | Manual financial tracking                    |

#### Media Domain

| Document                                                        | Integration | Type    | Description             |
| --------------------------------------------------------------- | ----------- | ------- | ----------------------- |
| [SPOTIFY_INTEGRATION.md](Integrations/SPOTIFY_INTEGRATION.md)   | Spotify     | OAuth   | Music listening history |
| [KARAKEEP_INTEGRATION.md](Integrations/KARAKEEP_INTEGRATION.md) | Karakeep    | API Key | Karaoke song tracking   |

#### Knowledge Domain

| Document                                                      | Integration | Type    | Description                      |
| ------------------------------------------------------------- | ----------- | ------- | -------------------------------- |
| [OUTLINE_INTEGRATION.md](Integrations/OUTLINE_INTEGRATION.md) | Outline     | API Key | Knowledge base document tracking |

#### Online/Social Domain

| Document                                                                | Integration    | Type    | Description                            |
| ----------------------------------------------------------------------- | -------------- | ------- | -------------------------------------- |
| [GITHUB_INTEGRATION.md](Integrations/GITHUB_INTEGRATION.md)             | GitHub         | OAuth   | Repository activity and commits        |
| [BLUESKY_INTEGRATION.md](Integrations/BLUESKY_INTEGRATION.md)           | BlueSky        | OAuth   | Social media posts                     |
| [REDDIT_INTEGRATION.md](Integrations/REDDIT_INTEGRATION.md)             | Reddit         | OAuth   | Reddit posts and comments              |
| [SLACK_INTEGRATION.md](Integrations/SLACK_INTEGRATION.md)               | Slack          | Webhook | Slack messages                         |
| [DAILYCHECKIN_INTEGRATION.md](Integrations/DAILYCHECKIN_INTEGRATION.md) | Daily Check-in | Manual  | Manual check-ins with location support |
| [TASK_INTEGRATION.md](Integrations/TASK_INTEGRATION.md)                 | Task Runner    | Special | Automated task execution               |

#### Web Content Domain

| Document                                                  | Integration | Type   | Description                             |
| --------------------------------------------------------- | ----------- | ------ | --------------------------------------- |
| [FETCH_INTEGRATION.md](Integrations/FETCH_INTEGRATION.md) | Fetch       | Manual | Web scraping with Playwright automation |

### User Interface

| Document                                                   | Description                                   |
| ---------------------------------------------------------- | --------------------------------------------- |
| [BLOCK_CARDS.md](UI%20and%20UX/BLOCK_CARDS.md)             | Block card display system with custom layouts |
| [DESIGN_PATTERNS.md](UI%20and%20UX/DESIGN_PATTERNS.md)     | UI consistency and visual hierarchy           |
| [EVENTS_INTERFACE.md](UI%20and%20UX/EVENTS_INTERFACE.md)   | Event data model and interface                |
| [UPDATES_INTERFACE.md](UI%20and%20UX/UPDATES_INTERFACE.md) | Integration update system                     |

### Guides

| Document                                                    | Description                        |
| ----------------------------------------------------------- | ---------------------------------- |
| [aws-s3-setup.md](Guides/aws-s3-setup.md)                   | AWS S3 media storage configuration |
| [aws-ses-receipt-setup.md](Guides/aws-ses-receipt-setup.md) | AWS SES email receipt setup        |

## Documentation Standards

All documentation in this project follows the [DOCS_STYLE_GUIDE.md](DOCS_STYLE_GUIDE.md). Key points:

- Use clear, concise language
- Include working code examples
- Follow the templates for integrations and features
- Keep documentation up-to-date with code changes

## Contributing to Documentation

1. Follow the [DOCS_STYLE_GUIDE.md](DOCS_STYLE_GUIDE.md)
2. Use the appropriate template for your document type
3. Test all code examples
4. Update this index when adding new documents
5. Review the checklist in the style guide before committing

## Getting Help

- Check [CLAUDE.md](../CLAUDE.md) for development guidance
- Search existing documentation for related topics
- Create an issue for documentation gaps or errors

## Navigating by User Type

**For Developers:**

- Start with [CLAUDE.md](../CLAUDE.md) for development setup
- Review [Core Architecture](#core-architecture) to understand the data model
- Check [Core Systems](#core-systems) for plugin and job patterns
- Refer to [DOCS_STYLE_GUIDE.md](DOCS_STYLE_GUIDE.md) when writing documentation

**For Integration Developers:**

- Read [INTEGRATION_PLUGINS.md](Architecture/INTEGRATION_PLUGINS.md) for plugin architecture
- Study existing integration docs in [Integrations](#integrations) directory
- Review [EVENTS.md](Architecture/EVENTS.md), [OBJECTS.md](Architecture/OBJECTS.md), and [BLOCKS.md](Architecture/BLOCKS.md) for data models
- Check [JOBS.md](Architecture/JOBS.md) for job patterns

**For UI/UX Developers:**

- Review [User Interface](#user-interface) documentation
- Check [BLOCK_CARDS.md](UI%20and%20UX/BLOCK_CARDS.md) for block display patterns
- Study [DESIGN_PATTERNS.md](UI%20and%20UX/DESIGN_PATTERNS.md) for consistency guidelines

**For Users:**

- Check integration-specific documentation in [Integrations](#integrations)
- Review [Guides](#guides) for setup instructions
