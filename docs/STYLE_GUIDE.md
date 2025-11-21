# Documentation Style Guide

This guide establishes consistent standards for all documentation in the Spark project. All contributors should follow these guidelines when writing or updating documentation.

## Table of Contents

- [General Principles](#general-principles)
- [Document Structure](#document-structure)
- [Writing Style](#writing-style)
- [Formatting Conventions](#formatting-conventions)
- [Code Examples](#code-examples)
- [Integration Documentation Template](#integration-documentation-template)
- [Feature Documentation Template](#feature-documentation-template)

## General Principles

### Audience

Documentation is written for developers who:

- Have basic Laravel/PHP knowledge
- Are new to the Spark codebase
- Need to understand, maintain, or extend features

### Goals

- **Clarity**: Use simple, direct language
- **Completeness**: Cover all essential information
- **Consistency**: Follow established patterns
- **Currency**: Keep documentation up-to-date with code changes

### Tone

- Use active voice and present tense
- Be direct and concise
- Avoid jargon unless defining it
- No emojis in documentation (keep it professional and accessible)

## Document Structure

### Required Sections

Every documentation file must include:

1. **Title** (H1): Clear, descriptive name
2. **Introduction**: 1-2 sentences explaining the purpose
3. **Table of Contents**: For documents over 100 lines (use Markdown links)
4. **Main Content**: Organized with H2/H3 headings
5. **Related Documentation**: Links to related docs (when applicable)

### Heading Hierarchy

```markdown
# Document Title (H1) - Only one per document

## Major Section (H2)

### Subsection (H3)

#### Detail (H4) - Use sparingly
```

### File Naming

- Use `UPPERCASE_WITH_UNDERSCORES.md` for primary documentation
- Use `lowercase-with-dashes.md` for supplementary guides
- Integration docs: `{SERVICE}_INTEGRATION.md` (e.g., `SPOTIFY_INTEGRATION.md`)
- Feature docs: `{FEATURE}.md` (e.g., `SPOTLIGHT.md`)

## Writing Style

### Sentence Structure

- Keep sentences under 25 words when possible
- One idea per sentence
- Use bullet points for lists of 3+ items

### Terminology

Use consistent terminology throughout:

| Term | Usage |
|------|-------|
| Integration | External service connection (Spotify, Monzo, etc.) |
| Plugin | Code implementing an integration |
| Event | A timestamped data point |
| EventObject | An entity that events relate to |
| Block | Aggregated/formatted data for display |
| Instance | A specific integration configuration |
| IntegrationGroup | Shared credentials for multiple instances |

### Capitalization

- Product names: Capitalize (Spark, Laravel, Livewire)
- Features: Capitalize when referring to the Spark feature (Spotlight, Card Streams)
- Technical terms: Use standard casing (OAuth, API, webhook)
- Code references: Use backticks and exact casing (`BaseFetchJob`)

## Formatting Conventions

### Code Blocks

Always specify the language for syntax highlighting:

```php
// PHP code
$integration->fetchData();
```

```bash
# Shell commands
sail artisan migrate
```

```json
{
    "key": "value"
}
```

```blade
{{-- Blade template --}}
<x-block-card :block="$block" />
```

### Inline Code

Use backticks for:

- File paths: `app/Integrations/Spotify/SpotifyPlugin.php`
- Class names: `BaseFetchJob`
- Method names: `fetchData()`
- Configuration keys: `update_frequency_minutes`
- Environment variables: `SPOTIFY_CLIENT_ID`
- Command names: `sail artisan horizon`

### Tables

Use tables for:

- Configuration options
- API endpoints
- Comparison of features

```markdown
| Column 1 | Column 2 | Column 3 |
|----------|----------|----------|
| Value    | Value    | Value    |
```

### Lists

Use bullet points for unordered information:

```markdown
- First item
- Second item
- Third item
```

Use numbered lists for sequential steps:

```markdown
1. First step
2. Second step
3. Third step
```

### Admonitions

Use blockquotes with bold labels for important notes:

```markdown
> **Note**: Additional information that may be helpful.

> **Warning**: Important information about potential issues.

> **Important**: Critical information that must not be missed.
```

### Links

- Use relative links for internal documentation: `[Style Guide](STYLE_GUIDE.md)`
- Use full URLs for external resources
- Use descriptive link text, not "click here"

## Code Examples

### Requirements

- All code examples must be tested and working
- Include necessary imports/use statements
- Show context (what file, what class)
- Add comments for non-obvious code

### Format

```php
// Location: app/Jobs/OAuth/Spotify/SpotifyListeningPull.php

namespace App\Jobs\OAuth\Spotify;

use App\Jobs\Base\BaseFetchJob;

class SpotifyListeningPull extends BaseFetchJob
{
    protected function fetchData(): array
    {
        // Implementation details
        return $this->client->get('/me/player/recently-played');
    }
}
```

### Good vs Bad Examples

When showing anti-patterns, clearly label them:

```php
// CORRECT: Use createBlock() for deduplication
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,
]);

// INCORRECT: This creates duplicate blocks
$event->blocks()->create([
    'title' => 'Heart Rate',
    'value' => 75,
]);
```

## Integration Documentation Template

Use this template for all integration documentation:

```markdown
# {Service} Integration

{One-sentence description of what this integration does.}

## Overview

{2-3 sentences expanding on the purpose, what data it tracks, and key features.}

## Features

- Feature 1: Brief description
- Feature 2: Brief description
- Feature 3: Brief description

## Setup

### Prerequisites

- Required accounts or API access
- Required environment variables

### Configuration

1. Step-by-step setup instructions
2. Include environment variable examples
3. Note any required OAuth scopes or permissions

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `SERVICE_CLIENT_ID` | OAuth client ID | Yes |
| `SERVICE_CLIENT_SECRET` | OAuth client secret | Yes |

## Data Model

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `action_name` | What this action represents | unit |

### Block Types

| Block Type | Description | Data Included |
|------------|-------------|---------------|
| `block_type` | What this block represents | Key data fields |

### Object Types

| Object Type | Description |
|-------------|-------------|
| `object_type` | What this object represents |

## Usage

### Connecting the Integration

Step-by-step user instructions for connecting.

### Manual Operations

```bash
# Command examples
sail artisan service:fetch --user=123
```

## API Reference

### Endpoints Used

Document which external API endpoints are called.

### Rate Limits

Note any rate limiting considerations.

## Troubleshooting

### Common Issues

1. **Issue Name**
   - Symptoms
   - Solution

## Related Documentation

- [Related Doc 1](RELATED_DOC.md)
- [Related Doc 2](RELATED_DOC.md)
```

## Feature Documentation Template

Use this template for feature documentation:

```markdown
# {Feature Name}

{One-sentence description of what this feature does.}

## Overview

{2-3 sentences explaining the feature's purpose and how it fits into Spark.}

## Architecture

### Components

- Component 1: Description
- Component 2: Description

### Data Flow

Describe how data flows through the feature.

## Usage

### Basic Usage

Code examples showing typical usage.

### Advanced Usage

Code examples for complex scenarios.

## Configuration

### Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `option` | type | default | Description |

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `VAR_NAME` | Description | Yes/No |

## Development

### Adding New {Component}

Step-by-step instructions for extending the feature.

### Testing

How to test this feature.

## Related Documentation

- [Related Doc](RELATED_DOC.md)
```

## Maintaining Documentation

### When to Update

Update documentation when:

- Adding new features or integrations
- Changing existing behavior
- Fixing bugs that affect documented behavior
- Deprecating features

### Review Checklist

Before committing documentation:

- [ ] Follows this style guide
- [ ] Code examples are tested
- [ ] Links work correctly
- [ ] No spelling/grammar errors
- [ ] Table of contents is accurate (if applicable)
- [ ] Related documentation links are updated

## Related Documentation

- [CLAUDE.md](../CLAUDE.md) - Development guide
- [WARP.md](../WARP.md) - Quick start guide
- [README.md](README.md) - Documentation index
