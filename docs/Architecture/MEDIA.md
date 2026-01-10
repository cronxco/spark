## Media Management with Spatie Media Library

Spark uses Spatie Media Library for managing media attachments (images, videos, documents) with MD5-based deduplication to ensure storage efficiency and minimize costs.

### Overview

The media system ensures that identical files (by MD5 hash) are only stored once in S3, while multiple EventObjects and Blocks can reference the same file. This provides:

- **Storage efficiency**: One copy per unique file, regardless of how many models reference it
- **Bandwidth optimization**: Deduplicated uploads save API calls and transfer costs
- **Automatic conversions**: Thumbnails, responsive images, and WebP variants
- **Private S3 storage**: Temporary signed URLs for secure access
- **Smart deletion**: Media is only removed when the last reference is deleted

### Architecture

**Models with Media Support:**

- `EventObject` - Supports collections: `screenshots`, `pdfs`, `downloaded_images`, `downloaded_videos`, `downloaded_documents`
- `Block` - Supports collections: `downloaded_images`, `downloaded_videos`, `downloaded_documents`

**Key Components:**

- `MD5PathGenerator` - Generates storage paths based on MD5 hash (ensures deduplication)
- `MediaDeduplicationService` - Handles finding existing media and reference counting
- `MediaDownloadHelper` - Main interface for downloading and attaching media with deduplication
- `MigrateExternalMediaUrlJob` - Migrates legacy `media_url` fields to Media Library

### Using MediaDownloadHelper

This is the primary interface for working with media in integration jobs:

```php

use App\Services\Media\MediaDownloadHelper;

$helper = app(MediaDownloadHelper::class);

// Download from URL
$media = $helper->downloadAndAttachMedia(
    'https://example.com/image.jpg',
    $eventObject,                    // Model instance
    'downloaded_images',             // Collection name
    ['custom_field' => 'value']      // Optional custom properties
);

// Attach from base64 (e.g., screenshots)
$media = $helper->attachMediaFromBase64(
    $base64Data,
    $eventObject,
    'screenshot.png',                // Filename
    'screenshots'                    // Collection name
);
```

**Deduplication behavior:**

- If the MD5 hash already exists, a new media record is created that references the same file
- The file itself is NOT re-uploaded to S3
- Both models will have their own media records, but share the same file

### Media Collections

**EventObject Collections:**

- `screenshots` - Browser screenshots from Playwright/Fetch
- `pdfs` - PDF documents
- `downloaded_images` - Images from integration APIs
- `downloaded_videos` - Video files
- `downloaded_documents` - Other document types

**Block Collections:**

- `downloaded_images` - Images (album art, previews, etc.)
- `downloaded_videos` - Video content
- `downloaded_documents` - Documents

**Choose the right collection** based on the source and type of media.

### Image Conversions

Automatic conversions are generated for images:

- **thumbnail** (300x300) - For card grids, non-queued for immediate availability
- **medium** (800px width) - For detail views and modals
- **webp** (800px width) - WebP format for better compression

Conversions are queued by default (except thumbnail) and processed via Horizon.

### Displaying Media in Views

Use the helper functions in blade templates:

```blade
{{-- Get media URL with fallback to media_url field --}}
@php
$imageUrl = get_media_url($block, 'downloaded_images', 'thumbnail');
@endphp

@if ($imageUrl)
    <img src="{{ $imageUrl }}" alt="{{ $block->title }}" loading="lazy">
@endif

{{-- For private S3, use temporary signed URLs --}}
@php
$imageUrl = get_media_temporary_url(
    $block,
    'downloaded_images',  // Collection
    'medium',             // Conversion
    60                    // Expiration minutes (default: 60)
);
@endphp
```

**Helper functions:**

- `get_media_url($model, $collection, $conversion)` - Gets Media Library URL or falls back to `media_url`
- `get_media_temporary_url($model, $collection, $conversion, $expirationMinutes)` - Gets signed URL for private S3

These helpers automatically:

- Check for Media Library attachments first
- Fall back to `media_url` field for backward compatibility
- Handle conversion selection
- Generate temporary URLs for S3 private buckets

### Migrating Existing Media URLs

For existing records with `media_url` fields pointing to external URLs:

```bash
# Dry run to see what would be migrated

sail artisan media:migrate-external-urls --dry-run

# Migrate EventObjects only

sail artisan media:migrate-external-urls --model=EventObject

# Migrate Blocks only

sail artisan media:migrate-external-urls --model=Block

# Migrate both (default)
sail artisan media:migrate-external-urls

# Limit for testing

sail artisan media:migrate-external-urls --limit=100
```

The migration:

- Runs on the `migration` queue
- Downloads each external URL
- Deduplicates by MD5 hash
- Attaches to Media Library
- Keeps `media_url` field intact (for rollback safety)
- Tracks progress via ActionProgress model

### Soft Deletion and Reference Counting

When a model with media is soft-deleted:

```php
// Automatic behavior in EventObject/Block models

static::deleting(function ($model) {
    $deduplicationService = app(MediaDeduplicationService::class);

    foreach ($model->media as $media) {
        // Only deletes file if this is the last reference
        $deduplicationService->deleteMedia($media, forceDelete: false);
    }
});
```

**Reference counting logic:**

- If multiple models reference the same file (same MD5), only the media record is soft-deleted
- The file remains in S3 until the last reference is deleted
- Force delete is supported for cleanup operations

### Storage Configuration

**Local Development:**

```env

MEDIA_DISK=local
```

**Production (S3):**

```env

MEDIA_DISK=s3

AWS_ACCESS_KEY_ID=your_key

AWS_SECRET_ACCESS_KEY=your_secret

AWS_DEFAULT_REGION=eu-west-2

AWS_BUCKET=your_bucket_name

MEDIA_TEMPORARY_URL_DEFAULT_LIFETIME=60
```

**Path Structure:** Files are stored using MD5 hash paths to enable deduplication:

```
s3://bucket/ab/cd/abcdef123.../original.jpg

s3://bucket/ab/cd/abcdef123.../conversions/thumbnail.jpg

s3://bucket/ab/cd/abcdef123.../conversions/medium.jpg
```

Different files with the same hash share the same directory.

### Activity Logging

Media operations are automatically logged via Spatie ActivityLog:

- Media attachments are logged when models are updated
- Media deletions are logged
- Integrates with existing changelog system

### Testing

**Unit tests** for deduplication logic:

```bash

sail artisan test --filter MD5PathGeneratorTest

sail artisan test --filter MediaDeduplicationServiceTest
```

**Storage::fake() for feature tests:**

```php

use Illuminate\Support\Facades\Storage;

Storage::fake('public');
config(['media-library.disk_name' => 'public']);

// Now test media operations
```

### Troubleshooting

**Media not appearing:**

- Check that model has `HasMedia` trait
- Verify media collection is registered in `registerMediaCollections()`
- Ensure `MEDIA_DISK` is correctly configured

**Conversions not generating:**

- Check Horizon is running: `sail artisan horizon`
- Verify queue workers are processing the `default` queue
- Check logs for ImageMagick/GD errors

**S3 upload failures:**

- Verify AWS credentials are correct
- Check IAM policy allows `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`
- Ensure bucket exists and region matches

**Duplicate files in S3:**

- This shouldn't happen - MD5PathGenerator ensures deduplication
- Check that custom properties include `md5_hash`
- Verify MediaDownloadHelper is being used (not direct addMedia calls)

### Best Practices

1. **Always use MediaDownloadHelper** - Don't call `addMedia()` or `addMediaFromUrl()` directly
2. **Choose appropriate collections** - Use `screenshots` for screenshots, `downloaded_images` for API images, etc.
3. **Leverage conversions** - Use `thumbnail` for cards, `medium` for detail views
4. **Monitor storage costs** - Deduplication significantly reduces S3 costs, but monitor usage
5. **Queue conversions** - Let Horizon handle image processing asynchronously
6. **Use signed URLs in production** - Set S3 bucket to private and use `get_media_temporary_url()`
7. **Test migration on staging** - Run `--dry-run` first, then migrate a limited batch

### AWS S3 Setup

See the dedicated AWS setup guide below for:

- Creating an S3 bucket (eu-west-2, private)
- Configuring IAM policies
- Setting up CORS for signed URLs
- Security best practices
