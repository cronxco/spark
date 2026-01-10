# AWS SES Receipt Email Setup Guide

This guide provides step-by-step instructions for setting up AWS SES (Simple Email Service) to receive receipt emails for the Spark Receipt plugin.

## Architecture Overview

```
Receipt Email → SES (Email Receiving) → S3 (Storage) → SNS (Notification) → Spark Webhook → Processing Queue
```

**Flow:**

1. User forwards receipt to `receipts@spark.cronx.co`
2. AWS SES receives email and stores in S3
3. SES triggers SNS notification
4. SNS calls Spark webhook with S3 object key
5. Spark downloads email, extracts data with GPT-5
6. Spark matches receipt to transactions automatically

## Prerequisites

- AWS Account with admin access
- Domain access to add DNS records (e.g., `spark.cronx.co`)
- Spark app deployed with publicly accessible webhook URL
- AWS CLI (optional, but recommended for testing)

## Region Selection

⚠️ **IMPORTANT**: Email receiving is **not available in all AWS regions**.

**Supported regions for SES email receiving:**

- `us-east-1` (N. Virginia) ✅
- `us-west-2` (Oregon) ✅
- `eu-west-1` (Ireland) ✅

**Recommended:** Use **eu-west-1 (Ireland)** for European deployments, as it's the only EU region supporting email receiving and is closest to London.

**Note:** Your S3 bucket can be in a different region (e.g., `eu-west-2` London). Cross-region access is supported.

---

## Step 1: Create S3 Bucket for Email Storage

### 1.1 Navigate to S3 Console

Go to: https://s3.console.aws.amazon.com/s3/buckets?region=eu-west-2

**Region:** `eu-west-2` (London) - or your preferred region

### 1.2 Create Bucket

- Click **"Create bucket"**
- **Bucket name**: `spark-receipts-emails` (must be globally unique, add suffix if taken)
- **AWS Region**: `eu-west-2` (London)
- **Object Ownership**: ACLs disabled (recommended)
- **Block Public Access**: ✅ Block all public access (keep emails private)
- **Bucket Versioning**: Disabled
- **Default encryption**:
    - Encryption type: **Server-side encryption with Amazon S3 managed keys (SSE-S3)**
    - Bucket Key: Enabled (reduces costs)
- Click **"Create bucket"**

### 1.3 Add Lifecycle Policy for 30-Day Retention

- Select your bucket → **Management** tab
- Click **"Create lifecycle rule"**
- **Lifecycle rule name**: `delete-old-receipts`
- **Choose a rule scope**: ✅ Apply to all objects in the bucket
- **Lifecycle rule actions**: ✅ Expire current versions of objects
- **Days after object creation**: `30`
- ✅ Acknowledge warning
- Click **"Create rule"**

### 1.4 Note the Bucket ARN

```
arn:aws:s3:::spark-receipts-emails
```

You'll need this for the SES bucket policy.

---

## Step 2: Configure SES Email Receiving

### 2.1 Verify Domain Identity

**Navigate to:** https://console.aws.amazon.com/ses/home?region=eu-west-1#/verified-identities

⚠️ **Region:** `eu-west-1` (Ireland) - Must be a region supporting email receiving

- Click **"Create identity"**
- **Identity type**: ✅ Domain
- **Domain**: `spark.cronx.co`
- **Assign a default configuration set**: Leave blank
- **Advanced DKIM settings**:
    - ✅ Easy DKIM (recommended)
    - DKIM signing key length: **RSA_2048_BIT**
- **Custom MAIL FROM domain**: Leave blank (optional, not needed for receiving)
- Click **"Create identity"**

### 2.2 Add DNS Records

SES will show you 3-4 DNS records to add. Add these to your DNS provider (CloudFlare/Route53/etc.):

**DKIM Records (3x CNAME):**

```
Name: abc123._domainkey.spark.cronx.co
Type: CNAME
Value: abc123.dkim.amazonses.com
```

_(Repeat for all 3 DKIM records shown)_

**MX Record for Email Receiving:**

```
Name: receipts.spark.cronx.co
Type: MX
Priority: 10
Value: inbound-smtp.eu-west-1.amazonaws.com
```

⚠️ **Important:** The MX record points to the **SES receiving region** (`eu-west-1`), not your S3 bucket region.

**TXT Record for Verification (if shown):**

```
Name: _amazonses.spark.cronx.co
Type: TXT
Value: [long verification string from SES]
```

### 2.3 Wait for Verification

- Refresh the SES console after 5-10 minutes
- **Domain status** should change to **"Verified"**
- **DKIM status** should be **"Successful"**

**Verify DNS propagation:**

```bash
dig MX receipts.spark.cronx.co
# Should return: inbound-smtp.eu-west-1.amazonaws.com
```

---

## Step 3: Create SNS Topic

### 3.1 Navigate to SNS Console

Go to: https://console.aws.amazon.com/sns/v3/home?region=eu-west-1#/topics

**Region:** `eu-west-1` (Ireland) - Must match SES region

### 3.2 Create Topic

- Click **"Create topic"**
- **Type**: ✅ Standard (FIFO not needed)
- **Name**: `receipt-emails-topic`
- **Display name**: Leave blank (optional)
- **Encryption**: Leave default (no encryption needed for metadata)
- **Access policy**: Leave as default (will modify later if needed)
- Click **"Create topic"**

### 3.3 Note the Topic ARN

```
arn:aws:sns:eu-west-1:123456789012:receipt-emails-topic
```

Save this for your `.env` file (`AWS_SNS_RECEIPT_TOPIC_ARN`).

---

## Step 4: Create SNS Subscription (Webhook)

### 4.1 Generate Webhook Secret

Run this in your Laravel app or locally:

```bash
php artisan tinker
>>> Str::random(32)
"abc123def456ghi789jkl012mno345pqr"
```

Save this secret - you'll use it in the webhook URL.

### 4.2 Subscribe Webhook Endpoint

- In the topic details page → **Subscriptions** tab
- Click **"Create subscription"**
- **Topic ARN**: (auto-filled)
- **Protocol**: **HTTPS**
- **Endpoint**:

    ```
    https://yourdomain.com/webhook/receipt/{secret}
    ```

    **Example:**

    ```
    https://spark.cronx.co/webhook/receipt/abc123def456ghi789jkl012mno345pqr
    ```

- **Enable raw message delivery**: ❌ Leave unchecked (we need the SNS wrapper)
- Click **"Create subscription"**

### 4.3 Confirm Subscription

⚠️ **Critical Step**: AWS will send a `SubscriptionConfirmation` POST request to your webhook within ~30 seconds.

**Your webhook must respond** by visiting the `SubscribeURL` from the JSON payload.

The existing WebhookController will handle this automatically, but you can monitor your Laravel logs:

```bash
tail -f storage/logs/laravel.log | grep -i "sns\|subscription"
```

**After confirmation:**

- Subscription **Status** in SNS console should change to **"Confirmed"**
- If stuck on "Pending", check your Laravel logs and ensure the webhook URL is publicly accessible

---

## Step 5: Create SES Receipt Rule Set

### 5.1 Navigate to Email Receiving

Go to: https://console.aws.amazon.com/ses/home?region=eu-west-1#/email-receiving

### 5.2 Create Rule Set (if none exists)

- Click **"Create rule set"**
- **Rule set name**: `default-rule-set`
- Click **"Create rule set"**

### 5.3 Make it Active

- Select the rule set
- Click **"Set as active rule set"**

### 5.4 Create Receipt Rule

- Inside the rule set, click **"Create rule"**

**Rule Details:**

- **Rule name**: `receipt-ingestion-rule`
- **Status**: ✅ Enabled
- **TLS**: ✅ Required (reject unencrypted emails)
- **Spam and virus scanning**: ✅ Enabled

**Recipient Conditions:**

- Click **"Add new recipient condition"**
- **Recipient**: `receipts@spark.cronx.co`

**Actions** (Add 2 actions in this order):

#### Action 1: S3 Action

- **Action type**: Deliver to Amazon S3 bucket
- **S3 bucket**: `spark-receipts-emails` (select from dropdown)
- **Object key prefix**: `incoming/` (optional, helps organize)
- **Message encryption**: None (bucket already encrypted)
- **SNS Topic**: None (will notify via Action 2)
- Click **"Add action"**

#### Action 2: SNS Action

- **Action type**: Publish to Amazon SNS topic
- **SNS topic**: `receipt-emails-topic` (select from dropdown)
- **Encoding**: ✅ UTF-8
- Click **"Add action"**

**Rule Settings:**

- **Rule position**: 1 (first rule)
- **Scan spam and viruses**: ✅ Enabled

Click **"Create rule"**

---

## Step 6: Update S3 Bucket Policy (Grant SES Access)

### 6.1 Go to S3 Bucket Permissions

- S3 Console → `spark-receipts-emails` → **Permissions** tab
- Scroll to **Bucket policy**
- Click **"Edit"**

### 6.2 Add This Policy

Replace placeholders:

- `spark-receipts-emails` → your bucket name
- `123456789012` → your AWS Account ID (find in top-right corner of console)
- `eu-west-1` → your SES region

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowSESPuts",
            "Effect": "Allow",
            "Principal": {
                "Service": "ses.amazonaws.com"
            },
            "Action": "s3:PutObject",
            "Resource": "arn:aws:s3:::spark-receipts-emails/*",
            "Condition": {
                "StringEquals": {
                    "AWS:SourceAccount": "123456789012"
                },
                "StringLike": {
                    "AWS:SourceArn": "arn:aws:ses:eu-west-1:123456789012:*"
                }
            }
        }
    ]
}
```

Click **"Save changes"**

---

## Step 7: Update Laravel Configuration

### 7.1 Environment Variables

Add these to your `.env` file:

```env
# Receipt Plugin Configuration
RECEIPT_DOMAIN=spark.cronx.co
RECEIPT_EMAIL_ADDRESS=receipts@spark.cronx.co
RECEIPT_WEBHOOK_SECRET=abc123def456ghi789jkl012mno345pqr

# AWS Receipt Storage (may differ from main AWS config)
AWS_REGION_RECEIPTS=eu-west-1
AWS_BUCKET_RECEIPTS=spark-receipts-emails
AWS_SNS_RECEIPT_TOPIC_ARN=arn:aws:sns:eu-west-1:123456789012:receipt-emails-topic

# Reuse existing AWS credentials (or create separate IAM user)
AWS_ACCESS_KEY_ID=your_existing_key
AWS_SECRET_ACCESS_KEY=your_existing_secret
```

### 7.2 Install Dependencies

The dependencies have been added to `composer.json`. Run:

```bash
composer update
```

This will install:

- `php-mime-mail-parser/php-mime-mail-parser` - MIME email parsing
- `html2text/html2text` - HTML to plain text conversion
- `smalot/pdfparser` - PDF text extraction

---

## Step 8: Grant IAM Permissions

Your Laravel app needs S3 read/delete access for the receipts bucket.

### 8.1 Find Your IAM User/Role

- If using EC2/ECS, this is an IAM Role
- If using external hosting, this is an IAM User with access keys

### 8.2 Add S3 Permissions

**Option A: Use existing S3 policy** (if you already have S3 access)

- Ensure your existing policy includes the receipts bucket

**Option B: Create custom policy (recommended)**

Go to IAM → Policies → **"Create policy"**

**Policy JSON:**

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ReceiptS3Access",
            "Effect": "Allow",
            "Action": ["s3:GetObject", "s3:DeleteObject", "s3:ListBucket"],
            "Resource": [
                "arn:aws:s3:::spark-receipts-emails",
                "arn:aws:s3:::spark-receipts-emails/*"
            ]
        }
    ]
}
```

- **Policy name**: `SparkReceiptS3Access`
- Click **"Create policy"**
- Attach this policy to your IAM user/role

---

## Step 9: Test the Integration

### 9.1 Send Test Email

From your personal email (Gmail, Outlook, etc.), send an email to:

```
receipts@spark.cronx.co
```

**Subject**: Test Receipt
**Body**:

```
Tesco Extra
123 High Street
London SW1A 1AA

Receipt #12345
Date: 15/01/2025 14:30

1x Bananas        £2.70
2x Milk 2L        £2.90

Subtotal:         £5.60
VAT (0%):         £0.00
Total:            £5.60

Paid by Card ending 1234
Thank you for shopping with us!
```

### 9.2 Verify in AWS

**Check S3:**

- S3 Console → `spark-receipts-emails` bucket
- You should see a file: `incoming/[long-random-string]`
- Download it and verify it's your email in RFC 822 format

**Check SNS:**

- SNS Console → Topics → `receipt-emails-topic` → **Monitoring** tab
- Should show 1 message published

**Check CloudWatch Logs** (if enabled):

- CloudWatch → Log groups → Search for SNS delivery logs

### 9.3 Verify in Laravel

Check `storage/logs/laravel.log`:

```bash
tail -f storage/logs/laravel.log | grep -i receipt
```

You should see:

```
[2025-01-15 14:32:01] local.INFO: Receipt: Processing receipt email from S3
[2025-01-15 14:32:03] local.INFO: Receipt: Downloaded email from S3
[2025-01-15 14:32:04] local.INFO: Receipt: Parsed email
[2025-01-15 14:32:06] local.INFO: Receipt: Extracting receipt data with GPT-5
[2025-01-15 14:32:08] local.INFO: Receipt: Successfully extracted receipt data
[2025-01-15 14:32:09] local.INFO: Receipt: Created receipt event and blocks
```

### 9.4 Check Laravel Horizon

- Go to `/horizon` in your app
- Check the **Recent Jobs** for `ProcessReceiptEmailJob`
- Should show as completed

---

## Step 10: Optional - Schedule Cleanup Job

To automatically delete S3 emails older than 30 days, add this to `app/Console/Kernel.php`:

```php
use App\Jobs\Data\Receipt\CleanupOldReceiptEmailsJob;

protected function schedule(Schedule $schedule): void
{
    // ... existing scheduled tasks ...

    // Clean up old receipt emails from S3 (30 day retention)
    $schedule->job(new CleanupOldReceiptEmailsJob)->daily();
}
```

**Note:** The S3 lifecycle policy (Step 1.3) also handles deletion, so this is a backup.

---

## Troubleshooting

### Subscription stays "Pending confirmation"

**Symptoms:** SNS subscription never confirms

**Solutions:**

- Check Laravel logs for the SubscriptionConfirmation message
- Ensure webhook route is publicly accessible (not behind auth)
- Temporarily disable CSRF protection for the webhook route if needed:
    ```php
    // In app/Http/Middleware/VerifyCsrfToken.php
    protected $except = [
        'webhook/receipt/*',
    ];
    ```
- Check SNS → Subscriptions → View delivery attempts for errors

### Emails not arriving in S3

**Symptoms:** No files appear in S3 bucket after sending email

**Solutions:**

- Check SES → Email receiving → Active rule set is enabled
- Verify MX record with: `dig MX receipts.spark.cronx.co`
- Check SES → Suppression list (your email might be blocked)
- Send from a different email address
- Check SES → Email receiving → Rule set → Metrics for incoming emails

### S3 PutObject Access Denied

**Symptoms:** SES can't write to S3 bucket

**Solutions:**

- Verify bucket policy has correct AWS account ID
- Check bucket policy syntax in AWS Policy Simulator
- Ensure SES and S3 regions match in the policy ARN
- Confirm bucket name is correct in policy

### SNS not notifying webhook

**Symptoms:** S3 file created but webhook never called

**Solutions:**

- Check SNS Topic → Subscriptions → Status is "Confirmed"
- View CloudWatch Metrics for SNS delivery failures
- Ensure your app's SSL certificate is valid (AWS rejects self-signed)
- Check webhook URL is publicly accessible: `curl -X POST https://yourdomain.com/webhook/receipt/test`
- Review SNS → Topics → Delivery status logs (if enabled)

### Laravel job fails: "Class not found"

**Symptoms:** `ProcessReceiptEmailJob` fails with class not found

**Solutions:**

- Run `composer dump-autoload`
- Run `composer update` to install new dependencies
- Check that all required packages are installed:
    ```bash
    composer show | grep -E "mime|html2text|pdfparser"
    ```

### GPT-5 extraction fails

**Symptoms:** Job fails at extraction step

**Solutions:**

- Check OpenAI API key is configured: `OPENAI_API_KEY` in `.env`
- Verify OpenAI API has access to GPT-5 model
- Check Laravel logs for OpenAI error details
- Ensure email text was extracted successfully (check logs)

### No matching transactions found

**Symptoms:** Receipts created but never matched

**Solutions:**

- Ensure Monzo/GoCardless integrations are active
- Check receipt time is within ±2 hours of transaction time
- Verify receipt amount matches transaction (±5% tolerance)
- Review merchant name normalization (check logs for merchant names)
- Manually match via UI (when implemented)

---

## Cost Estimates

**Monthly AWS Costs** (estimate for 100 receipts/month):

| Service           | Usage                     | Cost                        |
| ----------------- | ------------------------- | --------------------------- |
| SES Receiving     | 100 emails                | $0.10 per 1,000 = **$0.01** |
| S3 Storage        | ~50 MB (emails are small) | $0.023 per GB = **$0.02**   |
| SNS Notifications | 100 messages              | $0.50 per 1M = **$0.01**    |
| **Total**         |                           | **< $0.10/month**           |

**Note:** Costs scale linearly. For 1,000 receipts/month, expect ~$0.50/month.

---

## Security Considerations

1. **S3 Bucket**: Keep private (Block Public Access enabled)
2. **SNS Webhook**: Use secret token in URL for authentication
3. **SES Rule**: Enable spam/virus scanning (already configured)
4. **IAM Permissions**: Use least-privilege principle (only read/delete S3)
5. **Retention**: 30-day auto-delete minimizes data exposure
6. **HTTPS**: Always use HTTPS for webhook endpoints
7. **Email Content**: Receipt emails may contain sensitive data - treat accordingly

---

## Additional Resources

- [AWS SES Email Receiving Documentation](https://docs.aws.amazon.com/ses/latest/dg/receiving-email.html)
- [AWS SNS HTTPS Endpoints](https://docs.aws.amazon.com/sns/latest/dg/sns-http-https-endpoint-as-subscriber.html)
- [SES Supported Regions](https://docs.aws.amazon.com/general/latest/gr/ses.html)
- [S3 Lifecycle Policies](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lifecycle-mgmt.html)

---

## Support

If you encounter issues:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check Horizon dashboard: `/horizon`
3. Review AWS CloudWatch Logs (if enabled)
4. Test each step independently (S3 → SNS → Webhook)
5. Use AWS CLI to verify configurations:
    ```bash
    aws ses get-identity-verification-attributes --identities spark.cronx.co --region eu-west-1
    aws s3 ls s3://spark-receipts-emails/
    aws sns list-subscriptions-by-topic --topic-arn arn:aws:sns:eu-west-1:xxx:receipt-emails-topic
    ```

---

**Last Updated:** 2025-01-16
**Plugin Version:** 1.0.0
**Compatible with:** Laravel 12.x, Spark Receipt Plugin
