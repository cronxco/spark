# Newsletter Integration Setup Guide

This guide walks you through setting up the Newsletter integration in Spark to automatically process newsletter emails forwarded to `news@spark.cronx.co`.

## Overview

The Newsletter integration allows you to:

- Forward newsletter emails (e.g., from Substack, Morning Brew, etc.) to a dedicated email address
- Automatically extract clean content from newsletter HTML
- Generate AI-powered summaries (tweet, short, paragraph, key takeaways, TL;DR)
- Group newsletters by publication
- Tag content with topics, people, organizations, and places
- Search and browse your newsletter archive

## Architecture

```
Email newsletter → news@spark.cronx.co
    ↓
AWS SES receives email (eu-west-1)
    ↓
Stored in S3 bucket (eu-west-2)
    ↓
SNS notification sent to webhook
    ↓
Spark processes email
    ↓
AI extracts content + generates summaries
    ↓
Newsletters grouped by publication
```

## Prerequisites

- AWS Account with SES, S3, and SNS access
- Domain configured in AWS SES (e.g., `spark.cronx.co`)
- Spark instance running with webhook access

## Step 1: Environment Configuration

Add the following variables to your `.env` file:

```env
# Newsletter Integration
NEWSLETTER_DOMAIN=spark.cronx.co
NEWSLETTER_EMAIL_ADDRESS=news@spark.cronx.co
AWS_BUCKET_NEWSLETTERS=spark-newsletters-emails
AWS_REGION_NEWSLETTERS=eu-west-2
AWS_SNS_NEWSLETTER_TOPIC_ARN=arn:aws:sns:eu-west-1:YOUR_ACCOUNT_ID:newsletter-emails-topic
```

**Important Notes:**

- SES email receiving is **only available in eu-west-1** (Ireland), us-east-1 (Virginia), and us-west-2 (Oregon)
- You can store emails in S3 buckets in any region (cross-region access supported)
- The SNS topic must be in the same region as SES (eu-west-1)

## Step 2: AWS S3 Bucket Setup

### Create S3 Bucket for Newsletter Emails

1. Go to AWS S3 Console
2. Click **Create bucket**
3. Configure:
    - **Bucket name**: `spark-newsletters-emails`
    - **AWS Region**: `eu-west-2` (London) or your preferred region
    - **Block Public Access**: Keep all enabled (bucket should be private)
    - **Bucket Versioning**: Disabled
    - **Default encryption**: Amazon S3 managed keys (SSE-S3)

### Configure Lifecycle Policy (Optional)

To automatically delete old emails after 90 days:

1. Go to bucket → **Management** tab
2. Click **Create lifecycle rule**
3. Configure:
    - **Rule name**: `Delete old newsletters`
    - **Rule scope**: Apply to all objects
    - **Lifecycle rule actions**: ✓ Expire current versions of objects
    - **Days after object creation**: `90`
4. Save

## Step 3: AWS SNS Topic Setup

### Create SNS Topic

1. Go to AWS SNS Console (in **eu-west-1** region)
2. Click **Create topic**
3. Configure:
    - **Type**: Standard
    - **Name**: `newsletter-emails-topic`
    - **Display name**: Newsletter Emails
4. Click **Create topic**
5. Copy the **ARN** (you'll need this for the `.env` file)

### Subscribe Webhook to SNS Topic

You'll do this in Step 5 after creating the integration in Spark.

## Step 4: AWS SES Setup

### Verify Domain (if not already done)

1. Go to AWS SES Console (in **eu-west-1** region)
2. Navigate to **Verified identities**
3. Click **Create identity**
4. Select **Domain**
5. Enter your domain (e.g., `spark.cronx.co`)
6. Complete DNS verification by adding the required TXT/CNAME records

### Create Receipt Rule

1. In SES Console, navigate to **Email receiving** → **Rule sets**
2. If no rule set exists, create one and set it as active
3. Click **Create rule**
4. Configure **Recipient conditions**:
    - **Recipient**: `news@spark.cronx.co`
5. Click **Next**
6. Configure **Actions** (add TWO actions in this order):

    **Action 1: Deliver to S3 bucket**
    - **S3 bucket**: `spark-newsletters-emails`
    - **Object key prefix**: `emails/` (optional but recommended)
    - **Encryption**: None (or enable if preferred)

    **Action 2: Publish to Amazon SNS topic**
    - **SNS topic**: `newsletter-emails-topic`
    - **Encoding**: UTF-8

7. **Rule details**:
    - **Rule name**: `newsletter-delivery`
    - **Enabled**: ✓ Yes
8. Click **Create rule**

### Set Receipt Rule as Active

1. Ensure the rule set is **Active**
2. Verify the receipt rule is **Enabled**

### Grant S3 Permissions to SES

SES needs permission to write to your S3 bucket. Update the bucket policy:

1. Go to S3 bucket → **Permissions** tab
2. Edit **Bucket policy**
3. Add this policy (replace `YOUR_ACCOUNT_ID` and adjust bucket name if needed):

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
            "Resource": "arn:aws:s3:::spark-newsletters-emails/*",
            "Condition": {
                "StringEquals": {
                    "AWS:SourceAccount": "YOUR_ACCOUNT_ID"
                }
            }
        }
    ]
}
```

## Step 5: Create Integration in Spark

### Create Newsletter Integration

1. Log in to Spark
2. Navigate to **Integrations**
3. Click **Add Integration**
4. Select **Newsletter** from the list
5. Choose **Newsletters** instance type
6. Click **Create**

### Get Webhook URL and Secret

After creating the integration:

1. The integration will have a unique webhook secret
2. The webhook URL will be: `https://spark.cronx.co/webhook/newsletter/{secret}`
3. Copy this URL - you'll need it for the next step

## Step 6: Subscribe Webhook to SNS Topic

### Add HTTPS Subscription

1. Go back to AWS SNS Console (eu-west-1)
2. Navigate to **Topics** → `newsletter-emails-topic`
3. Click **Create subscription**
4. Configure:
    - **Protocol**: HTTPS
    - **Endpoint**: `https://spark.cronx.co/webhook/newsletter/{your-secret}`
    - **Enable raw message delivery**: No (leave unchecked)
5. Click **Create subscription**

### Confirm Subscription

The subscription will be in **Pending confirmation** state. Within a few seconds:

1. Spark will receive the subscription confirmation request
2. The webhook will automatically confirm the subscription
3. Refresh the SNS subscription page - status should change to **Confirmed**

If it doesn't auto-confirm:

- Check Spark logs for webhook errors
- Verify the webhook URL is correct
- Ensure the integration secret matches

## Step 7: Test the Integration

### Send a Test Email

1. Forward a newsletter email to `news@spark.cronx.co`
2. Or compose a new email and send it to `news@spark.cronx.co`

### Monitor Processing

Check the following:

1. **AWS S3 Bucket**: Email should appear in `spark-newsletters-emails/emails/`
2. **Spark Logs**: Watch for processing activity:
    ```bash
    sail artisan pail --filter="Newsletter:"
    ```
3. **Laravel Horizon**: Check queue jobs:
    ```bash
    sail artisan horizon
    ```
    Or visit: `https://spark.cronx.co/horizon`

### View Results in Spark

1. Navigate to **Events** in Spark
2. Filter by service: **Newsletter**
3. You should see:
    - **Publication** EventObject created (e.g., "Morning Brew")
    - **Event** with action `received_post`
    - **5 Blocks**: Tweet summary, short summary, paragraph summary, key takeaways, TL;DR
    - **Tags**: Emoji + semantic tags (topics, people, organizations, places)

## Step 8: Forward Existing Newsletters

### Gmail Forwarding Rules

Set up filters to automatically forward newsletters:

1. In Gmail, go to **Settings** → **Filters and Blocked Addresses**
2. Click **Create a new filter**
3. Configure filter criteria:
    - **From**: `crew@morningbrew.com` (or sender address)
    - Or use **Subject** contains specific text
4. Click **Create filter**
5. Select actions:
    - ✓ **Forward it to**: `news@spark.cronx.co`
    - ✓ **Mark as read** (optional)
    - ✓ **Categorize as**: (optional, to keep inbox clean)
6. Click **Create filter**

Repeat for each newsletter you want to track.

### Other Email Clients

**Outlook/Microsoft 365:**

1. Rules → New Rule
2. Condition: From specific sender
3. Action: Forward to `news@spark.cronx.co`

**Apple Mail:**

1. Mail → Settings → Rules
2. Add Rule with forwarding action

## Troubleshooting

### Newsletter Email Not Received

**Check SES Receipt Rule:**

- Verify rule is enabled
- Verify recipient matches exactly
- Check rule order (earlier rules may intercept)

**Check SES Sending Limits:**

- New SES accounts start in sandbox mode
- Sandbox mode only accepts emails from verified addresses
- Request production access: SES Console → Account dashboard → Request production access

**Check Spam/Rejection:**

- SES may reject emails that fail SPF/DKIM checks
- Check SES → **Suppression list** for bounces

### Webhook Not Receiving Notifications

**Check SNS Subscription:**

- Status must be "Confirmed"
- Endpoint URL must match exactly
- HTTPS endpoint must be publicly accessible

**Check Webhook Logs:**

```bash
sail artisan pail --filter="Newsletter: Invalid SNS"
```

**Test SNS Manually:**

```bash
# In SNS Console, click "Publish message" on your topic
# Send test message and check if webhook receives it
```

### Email Parsed But No Content Extracted

**Check AI Processing:**

- Verify OpenAI API key is configured
- Check Horizon queue is running: `sail artisan horizon`
- Check for job failures in Horizon dashboard

**Check Email Format:**

- Newsletter must have HTML content
- Plain text-only emails may not work well
- Check logs for extraction errors

### Publication Not Grouping Correctly

Publications are grouped by sender name extracted from the `From` header.

**Check EventObjects:**

- Look for multiple publication EventObjects that should be one
- Publications with slightly different sender names create separate objects
- Manually merge if needed (future feature)

### OpenAI Costs Too High

**Reduce Newsletter Volume:**

- Be selective about which newsletters to forward
- Unsubscribe from newsletters you don't read

**Estimated Costs:**

- Content extraction: ~10,000 tokens per newsletter
- Summary generation: ~10,000 tokens per newsletter
- Total: ~20,000 tokens per newsletter
- Cost (gpt-5-nano): ~$0.01 per 100 newsletters
- Monthly cost: ~$1 for 100 newsletters/month

## Maintenance

### Monitor S3 Storage

Check bucket size periodically:

```bash
aws s3 ls s3://spark-newsletters-emails --recursive --summarize
```

### Monitor SNS Delivery

Check SNS metrics in CloudWatch:

- Topic: `newsletter-emails-topic`
- Metrics: Messages published, delivery attempts, failures

### Rotate Webhook Secret

If you need to change the webhook secret:

1. Create a new Newsletter integration in Spark
2. Get the new webhook URL
3. Update SNS subscription endpoint
4. Wait for confirmation
5. Delete old integration

## Cost Estimate

**AWS Costs:**

- SES receiving: Free (first 1,000 emails/month)
- S3 storage: ~$0.023/GB/month (emails are small, ~$0.01/month for 100 newsletters)
- SNS requests: ~$0.50/million (negligible for personal use)

**OpenAI Costs:**

- gpt-5-nano: ~$0.01 per 100 newsletters
- Monthly (100 newsletters): ~$1.00

**Total: ~$1-2/month for 100 newsletters**

## Security Considerations

### S3 Bucket Security

- ✓ Keep bucket private (no public access)
- ✓ Enable encryption at rest
- ✓ Use lifecycle policies to auto-delete old emails
- ✓ IAM policies grant least privilege access

### SNS Webhook Security

- ✓ Use HTTPS only (never HTTP)
- ✓ Webhook secret provides authentication
- ✓ SNS signature verification (built into Spark)
- ✓ Rotate secrets periodically

### Email Privacy

- ⚠️ Newsletter content is sent to OpenAI for processing
- ⚠️ Don't forward emails with sensitive/personal information
- ⚠️ Only forward newsletters from trusted sources

## Advanced Configuration

### Custom Email Domain

If you want to use `newsletters@yourdomain.com` instead:

1. Verify `yourdomain.com` in SES
2. Update `.env`:
    ```env
    NEWSLETTER_DOMAIN=yourdomain.com
    NEWSLETTER_EMAIL_ADDRESS=newsletters@yourdomain.com
    ```
3. Update SES receipt rule recipient

### Multiple Newsletter Addresses

You can create multiple integrations with different email addresses:

1. Create separate SES receipt rules for each address
2. Use same SNS topic or different topics
3. Each integration gets its own webhook secret

### Disable AI Processing

To only store newsletters without AI summaries:

1. Not currently supported via configuration
2. Would require code changes to skip ExtractContentJob and GenerateSummariesJob

## Support

For issues or questions:

- Check Spark logs: `sail artisan pail`
- Check Horizon queue: `https://spark.cronx.co/horizon`
- Review AWS SES metrics and logs
- Open an issue on GitHub

## Changelog

- **2025-01-XX**: Initial release of Newsletter integration
