# Monzo Integration

Connect your Monzo bank account to sync transactions, balances, and pot activity.

## Overview

The Monzo integration uses OAuth 2.0 to connect to your Monzo account and import financial data. It creates events for transactions, daily balances, and pot balances, along with EventObjects for accounts, pots, and counterparties. The integration supports historical backfill via the migration system and incremental updates via scheduled polling.

## Features

- OAuth 2.0 authentication with automatic token refresh
- Transaction sync with merchant details, foreign exchange rates, and categorization
- Daily balance snapshots with spend tracking
- Pot balance monitoring for savings goals
- Automatic counterparty detection and merchant enrichment
- Support for multiple account types (Current, Joint, Flex, Rewards)
- Historical data migration with 89-day windowed backfill
- Salary detection based on configurable employer name

## Setup

### Prerequisites

- Monzo developer account with OAuth client credentials
- Redis connection for queue processing
- Laravel Horizon for background job management

### Configuration

1. Register an OAuth client at the Monzo Developer Portal
2. Configure the redirect URI to match your application callback URL
3. Add environment variables to your `.env` file
4. Ensure Horizon is running with the `migration` queue enabled

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `MONZO_CLIENT_ID` | OAuth client ID from Monzo Developer Portal | Yes |
| `MONZO_CLIENT_SECRET` | OAuth client secret from Monzo Developer Portal | Yes |
| `MONZO_REDIRECT_URI` | OAuth callback URL (defaults to `/integrations/monzo/callback`) | No |
| `MONZO_SALARY_NAME` | Employer name for salary detection (BACS payments > 1500 GBP) | No |

## Data Model

### Instance Types

| Type | Label | Description |
|------|-------|-------------|
| `accounts` | Accounts (Master) | Seeds shared account and pot objects; does not create events |
| `transactions` | Transactions | Syncs transaction events from all accounts |
| `pots` | Pots | Syncs pot balance snapshots |
| `balances` | Balances | Syncs daily account balance snapshots |

### Action Types

| Action | Display Name | Description | Hidden |
|--------|--------------|-------------|--------|
| `had_balance` | Balance Update | Account balance was updated | Yes |
| `salary_received_from` | Salary Received | Salary payment received from employer | No |
| `declined_payment_to` | Declined Payment | Card payment was declined | No |
| `card_payment_to` | Card Payment | Payment made with Monzo card | No |
| `card_refund_from` | Card Refund | Refund received on Monzo card | No |
| `pot_transfer_to` | Pot Transfer | Money transferred to a Monzo pot | No |
| `pot_withdrawal_to` | Pot Withdrawal | Money withdrawn from a Monzo pot | No |
| `interest_repaid` | Interest Repaid | Interest payment made | No |
| `interest_earned` | Interest Earned | Interest received | No |
| `monzo_flex_payment` | Monzo Flex Payment | Payment made for Monzo Flex | No |
| `monzo_flex_loan` | Monzo Flex Loan | Money borrowed via Monzo Flex | No |
| `direct_debit_to` | Direct Debit | Direct debit payment | No |
| `direct_credit_from` | Direct Credit | Direct credit received | No |
| `monzo_me_to` | Monzo Me Sent | Money sent via Monzo Me | No |
| `monzo_me_from` | Monzo Me Received | Money received via Monzo Me | No |
| `bank_transfer_to` | Bank Transfer Sent | Money sent via bank transfer | No |
| `bank_transfer_from` | Bank Transfer Received | Money received via bank transfer | No |
| `fee_paid_for` | Fee Paid | Fee charged by Monzo | No |
| `fee_refunded_for` | Fee Refunded | Fee refunded by Monzo | No |
| `other_debit_to` | Other Debit | Other outgoing transaction | No |
| `other_credit_from` | Other Credit | Other incoming transaction | No |

### Block Types

| Type | Display Name | Description | Value Unit |
|------|--------------|-------------|------------|
| `balance` | Balance | Account balance information | - |
| `spent_today` | Spent Today | Amount spent today for this account | GBP |
| `balance_change` | Balance Change | Change in balance since previous day | GBP |
| `pot` | Pot | Monzo savings pot information | - |
| `foreign_exchange` | Foreign Exchange | Local vs transaction currency breakdown | - |
| `virtual_card` | Virtual Card | Virtual card details used for payment | - |
| `bank_transfer` | Bank Transfer | External bank transfer details | GBP |
| `transaction` | Transaction | Transaction information | - |
| `merchant` | Merchant | Merchant information for transaction | - |
| `pot_transfer` | Pot Transfer | Money transfer to or from a Monzo pot | GBP |

### Object Types

| Type | Display Name | Description |
|------|--------------|-------------|
| `monzo_account` | Monzo Account | A Monzo bank account (Current, Joint, Flex, etc.) |
| `monzo_pot` | Monzo Pot | An active Monzo savings pot |
| `monzo_archived_pot` | Archived Monzo Pot | A deleted or archived Monzo savings pot |
| `monzo_counterparty` | Counterparty | A transaction counterparty (merchant or recipient) |
| `day` | Day | A calendar day used as target for balance events |

## Usage

### Connecting

1. Navigate to `/integrations` and select Monzo
2. Click "Connect" to initiate OAuth flow
3. Approve the required scopes in the Monzo app
4. After callback, create instances for the data types you want to sync

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `update_frequency_minutes` | Integer | 30 | Polling interval for incremental updates (min: 5) |
| `include_pot_transfers` | Array | - | Enable processing of pot transfer transactions (transactions instance only) |

### Manual Operations

**Trigger historical migration:**

```php
$integration = \App\Models\Integration::where('service', 'monzo')->first();
dispatch(new \App\Jobs\Migrations\StartIntegrationMigration($integration))
    ->onConnection('redis')
    ->onQueue('migration');
```

**Monitor migration progress:**

Visit `/horizon` to view job processing status.

## API Reference

- **Base URL**: `https://api.monzo.com`
- **Auth URL**: `https://auth.monzo.com`
- **OAuth Scopes**: `accounts:read`, `transactions:read`, `balance:read`, `pots:read`

### Endpoints Used

| Endpoint | Description |
|----------|-------------|
| `GET /accounts` | List all accounts |
| `GET /balance` | Get account balance |
| `GET /pots` | List pots for an account |
| `GET /transactions` | List transactions with merchant expansion |
| `POST /oauth2/token` | Exchange code for tokens / refresh tokens |

### Money Encoding

All monetary values are stored as integer pence with `value_multiplier=100` and `value_unit=GBP` to avoid floating point errors.

## Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| OAuth callback fails | Verify `MONZO_REDIRECT_URI` matches the value configured in Monzo Developer Portal |
| Horizon not processing jobs | Check `QUEUE_CONNECTION=redis`, verify Horizon supervisors include `migration` queue, ensure Redis is available |
| No data created | Use `Http::fake()` in tests to validate processing; confirm OAuth credentials are correct |
| Token refresh fails | Ensure `MONZO_CLIENT_SECRET` is set; check Monzo API status; re-authenticate if refresh token is revoked |
| Duplicate events | Events are deduplicated by `source_id`; database unique index prevents race conditions |

## Related Documentation

- [Monzo API Documentation](https://docs.monzo.com/)
- [Monzo Developer Portal](https://developers.monzo.com/apps)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [CLAUDE.md - Integration Plugin System](/CLAUDE.md#integration-plugin-system)
