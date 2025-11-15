# AWS S3 Setup Guide for Spark Media Library

This guide walks you through setting up AWS S3 for Spark's Media Library with proper security and optimization.

## Prerequisites

- AWS account with appropriate permissions
- AWS CLI installed (optional, but recommended)
- Access to AWS Console

## Step 1: Create S3 Bucket

### Via AWS Console

1. Navigate to S3 in the AWS Console
2. Click "Create bucket"
3. Configure:
    - **Bucket name**: Choose a unique name (e.g., `spark-media-production`)
    - **Region**: `eu-west-2` (London) - must match `AWS_DEFAULT_REGION` in .env
    - **Block Public Access**: Keep ALL public access blocked (recommended for private media)
    - **Bucket Versioning**: Optional (disable for cost savings)
    - **Encryption**: Enable SSE-S3 (server-side encryption)
    - **Object Lock**: Disable

4. Click "Create bucket"

### Via AWS CLI

```bash
aws s3api create-bucket \
    --bucket spark-media-production \
    --region eu-west-2 \
    --create-bucket-configuration LocationConstraint=eu-west-2

# Enable encryption
aws s3api put-bucket-encryption \
    --bucket spark-media-production \
    --server-side-encryption-configuration '{
        "Rules": [{
            "ApplyServerSideEncryptionByDefault": {
                "SSEAlgorithm": "AES256"
            }
        }]
    }'

# Block public access
aws s3api put-public-access-block \
    --bucket spark-media-production \
    --public-access-block-configuration \
        BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
```

## Step 2: Create IAM User & Policy

### Create IAM Policy

Create a policy with minimum required permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "SparkMediaLibraryAccess",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:PutObjectAcl",
                "s3:GetObject",
                "s3:GetObjectAcl",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::spark-media-production",
                "arn:aws:s3:::spark-media-production/*"
            ]
        }
    ]
}
```

**Via AWS Console:**

1. Go to IAM → Policies → Create Policy
2. Select JSON tab and paste the policy above
3. Name it `SparkMediaLibraryPolicy`
4. Click "Create policy"

**Via AWS CLI:**

```bash
cat > spark-media-policy.json << 'EOF'
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "SparkMediaLibraryAccess",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:PutObjectAcl",
                "s3:GetObject",
                "s3:GetObjectAcl",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::spark-media-production",
                "arn:aws:s3:::spark-media-production/*"
            ]
        }
    ]
}
EOF

aws iam create-policy \
    --policy-name SparkMediaLibraryPolicy \
    --policy-document file://spark-media-policy.json
```

### Create IAM User

**Via AWS Console:**

1. Go to IAM → Users → Create User
2. User name: `spark-media-user`
3. Skip console access (programmatic access only)
4. Attach the `SparkMediaLibraryPolicy` policy
5. Create user and **save the access keys**

**Via AWS CLI:**

```bash
# Create user
aws iam create-user --user-name spark-media-user

# Attach policy
aws iam attach-user-policy \
    --user-name spark-media-user \
    --policy-arn arn:aws:iam::YOUR_ACCOUNT_ID:policy/SparkMediaLibraryPolicy

# Create access keys
aws iam create-access-key --user-name spark-media-user
```

**Important**: Save the `AccessKeyId` and `SecretAccessKey` immediately - you won't be able to retrieve the secret later.

## Step 3: Configure CORS (for Private Bucket Signed URLs)

CORS is required for browsers to access signed URLs from your application domain.

**CORS Configuration:**

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": [
            "https://yourdomain.com",
            "https://www.yourdomain.com",
            "http://localhost:3000"
        ],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3000
    }
]
```

**Via AWS Console:**

1. Go to your bucket → Permissions → CORS
2. Paste the configuration above (update domains)
3. Save changes

**Via AWS CLI:**

```bash
cat > cors-config.json << 'EOF'
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": [
            "https://yourdomain.com",
            "http://localhost:3000"
        ],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3000
    }
]
EOF

aws s3api put-bucket-cors \
    --bucket spark-media-production \
    --cors-configuration file://cors-config.json
```

## Step 4: Configure Laravel Environment

Update your `.env` file:

```env
# AWS S3 Configuration
AWS_ACCESS_KEY_ID=AKIA...YOUR_KEY...
AWS_SECRET_ACCESS_KEY=your_secret_access_key
AWS_DEFAULT_REGION=eu-west-2
AWS_BUCKET=spark-media-production

# Media Library Configuration
MEDIA_DISK=s3
MEDIA_TEMPORARY_URL_DEFAULT_LIFETIME=60
```

## Step 5: Test the Connection

Create a quick test script to verify connectivity:

```php
// routes/web.php or tinker
use Illuminate\Support\Facades\Storage;

// Test write
Storage::disk('s3')->put('test.txt', 'Hello from Spark!');

// Test read
$content = Storage::disk('s3')->get('test.txt');
dump($content); // Should output: "Hello from Spark!"

// Test temporary URL
$url = Storage::disk('s3')->temporaryUrl('test.txt', now()->addMinutes(5));
dump($url); // Should be a signed S3 URL

// Cleanup
Storage::disk('s3')->delete('test.txt');
```

Or use artisan tinker:

```bash
sail artisan tinker
>>> Storage::disk('s3')->put('test.txt', 'test');
>>> Storage::disk('s3')->temporaryUrl('test.txt', now()->addMinutes(5));
>>> Storage::disk('s3')->delete('test.txt');
```

## Security Best Practices

### 1. Use Private Buckets

**Never** make your media bucket public. Use temporary signed URLs instead:

```php
// Good - private bucket with signed URLs
$url = $media->getTemporaryUrl(now()->addHour());

// Bad - public bucket
// Don't do this!
```

### 2. Rotate Access Keys Regularly

Set up a schedule to rotate IAM user access keys every 90 days:

```bash
# Deactivate old key
aws iam update-access-key \
    --user-name spark-media-user \
    --access-key-id OLD_KEY_ID \
    --status Inactive

# Create new key
aws iam create-access-key --user-name spark-media-user

# Update .env with new keys
# Test application
# Delete old key after verification
aws iam delete-access-key \
    --user-name spark-media-user \
    --access-key-id OLD_KEY_ID
```

### 3. Enable CloudTrail Logging

Monitor S3 access for security auditing:

1. Enable CloudTrail in AWS Console
2. Create a trail for S3 data events
3. Monitor logs for suspicious activity

### 4. Use Bucket Policies (Optional)

Add an additional layer of security with bucket policies:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "DenyUnencryptedObjectUploads",
            "Effect": "Deny",
            "Principal": "*",
            "Action": "s3:PutObject",
            "Resource": "arn:aws:s3:::spark-media-production/*",
            "Condition": {
                "StringNotEquals": {
                    "s3:x-amz-server-side-encryption": "AES256"
                }
            }
        }
    ]
}
```

### 5. Enable MFA Delete (Production)

For critical production buckets:

```bash
# Enable versioning first
aws s3api put-bucket-versioning \
    --bucket spark-media-production \
    --versioning-configuration Status=Enabled,MFADelete=Enabled \
    --mfa "arn:aws:iam::ACCOUNT_ID:mfa/USERNAME TOKEN_CODE"
```

## Cost Optimization

### Storage Classes

For infrequently accessed media, consider lifecycle policies:

```json
{
    "Rules": [
        {
            "Id": "MoveToIA",
            "Status": "Enabled",
            "Transitions": [
                {
                    "Days": 90,
                    "StorageClass": "STANDARD_IA"
                },
                {
                    "Days": 180,
                    "StorageClass": "GLACIER_IR"
                }
            ]
        }
    ]
}
```

**Apply via AWS CLI:**

```bash
aws s3api put-bucket-lifecycle-configuration \
    --bucket spark-media-production \
    --lifecycle-configuration file://lifecycle-policy.json
```

### Monitor Costs

- Enable AWS Cost Explorer
- Set up billing alerts
- Monitor storage metrics in CloudWatch
- Track request costs (GET, PUT operations)

### Deduplication Savings

Spark's MD5-based deduplication significantly reduces storage costs:

- Example: 1000 identical 1MB images
    - Without deduplication: 1000 MB (~$0.023/month in S3 Standard)
    - With deduplication: 1 MB (~$0.000023/month)
    - Savings: 99.9%

## Troubleshooting

### Access Denied Errors

Check:

- IAM policy includes required actions (`s3:PutObject`, `s3:GetObject`, etc.)
- Bucket name matches exactly (case-sensitive)
- Region matches between bucket and .env configuration
- Access keys are valid and not expired

### CORS Errors in Browser

Check:

- CORS configuration includes your domain
- Browser console shows specific CORS error
- AllowedMethods includes GET for signed URLs
- MaxAgeSeconds is set appropriately

### Temporary URL Expiration

If URLs expire too quickly:

```env
MEDIA_TEMPORARY_URL_DEFAULT_LIFETIME=120  # 2 hours
```

If URLs don't work after generation:

- Check server time is synchronized (NTP)
- Verify IAM user has `s3:GetObject` permission
- Ensure bucket is in the same region as configured

### Performance Issues

- Use CloudFront CDN in front of S3 for better performance
- Enable S3 Transfer Acceleration for faster uploads
- Consider Regional vs Cross-Regional access patterns
- Monitor S3 request metrics in CloudWatch

## CloudFront CDN Setup (Optional)

For improved performance and caching:

1. Create CloudFront distribution
2. Set origin to your S3 bucket
3. Configure origin access identity (OAI)
4. Update bucket policy to allow CloudFront access
5. Point `AWS_URL` to CloudFront distribution

Benefits:

- Faster content delivery globally
- Reduced S3 request costs
- Better caching control
- HTTPS by default

## Support

For issues with AWS configuration:

- AWS Support: https://console.aws.amazon.com/support/
- AWS Documentation: https://docs.aws.amazon.com/s3/
- Laravel Media Library: https://spatie.be/docs/laravel-medialibrary/
