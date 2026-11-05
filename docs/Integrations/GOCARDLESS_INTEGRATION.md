# GoCardless Bank Integration

Connect to European bank accounts via the GoCardless Bank Account Data API to sync transactions and balances.

## Overview

The GoCardless Bank integration enables PSD2-regulated access to bank accounts across the European Economic Area (EEA). It supports fetching up to 24 months of transaction history, maintaining continuous access for up to 90 days, and retrieving account balances and details from multiple European banks.

## Features

- Connect to bank accounts through PSD2-regulated APIs
- Access up to 24 months of transaction history
- Maintain continuous access for up to 90 days
- Retrieve account balances and transaction details
- Support for multiple European countries and institutions
- Automatic token management per request
- Historical data migration support

## Setup

### Prerequisites

- GoCardless Bank Account Data API account
- API credentials (secret_id and secret_key)
- Configured redirect URI for OAuth flow

### Configuration

1. Sign up at [gocardless.com](https://gocardless.com) and access the Bank Account Data portal
2. Obtain your user secrets (secret_id and secret_key)
3. Configure your redirect URI in the GoCardless dashboard
4. Add environment variables to your `.env` file

### Environment Variables

| Variable                    | Required | Default                                         | Description                                    |
| --------------------------- | -------- | ----------------------------------------------- | ---------------------------------------------- |
| `GOCARDLESS_SECRET_ID`      | Yes      | -                                               | API secret ID from GoCardless                  |
| `GOCARDLESS_SECRET_KEY`     | Yes      | -                                               | API secret key from GoCardless                 |
| `GOCARDLESS_COUNTRY`        | No       | `GB`                                            | Default country code for institution selection |
| `GOCARDLESS_INSTITUTION_ID` | No       | -                                               | Pre-selected institution ID (optional)         |
| `GOCARDLESS_REDIRECT_URI`   | No       | `{APP_URL}/integrations/gocardless/callback`    | OAuth callback URL                             |
| `GOCARDLESS_API_BASE`       | No       | `https://bankaccountdata.gocardless.com/api/v2` | API base URL                                   |

## Data Model

### Instance Types

| Type           | Label             | Mandatory | Description                                         |
| -------------- | ----------------- | --------- | --------------------------------------------------- |
| `accounts`     | Accounts (master) | Yes       | Master instance for account management              |
| `transactions` | Transactions      | No        | Fetches recent transactions from connected accounts |
| `balances`     | Balances          | No        | Fetches current account balances                    |

### Action Types

| Type               | Display Name   | Icon                | Description                         | Hidden |
| ------------------ | -------------- | ------------------- | ----------------------------------- | ------ |
| `made_transaction` | Transaction    | `o-arrow-right`     | A bank transaction occurred         | No     |
| `payment_to`       | Payment Out    | `o-arrow-up-right`  | Money was sent from the account     | No     |
| `payment_from`     | Payment In     | `o-arrow-down-left` | Money was received into the account | No     |
| `had_balance`      | Balance Update | `o-currency-pound`  | Account balance was updated         | Yes    |

### Block Types

| Type                 | Display Name        | Icon                   | Description                                   |
| -------------------- | ------------------- | ---------------------- | --------------------------------------------- |
| `balance_snapshot`   | Account Balance     | `o-currency-pound`     | Current account balance snapshot              |
| `balance_change`     | Balance Change      | `o-arrow-trending-up`  | Details about a balance change transaction    |
| `balance_info`       | Balance Information | `o-information-circle` | Detailed balance information and metadata     |
| `transaction_status` | Transaction Status  | `o-clock`              | Status information for transaction processing |

### Object Types

| Type                       | Display Name             | Icon               | Description                                  |
| -------------------------- | ------------------------ | ------------------ | -------------------------------------------- |
| `bank_account`             | Bank Account             | `o-credit-card`    | A connected bank account                     |
| `transaction_counterparty` | Transaction Counterparty | `o-user`           | A transaction counterparty (creditor/debtor) |
| `balance_snapshot`         | Balance Snapshot         | `o-currency-pound` | A snapshot of account balance                |
| `day`                      | Day                      | `o-calendar`       | A calendar day for temporal context          |

## Usage

### Connecting

1. Click "Add Instance" to create an integration group
2. Select your bank from the list of available institutions
3. Authenticate with your bank via the GoCardless redirect flow
4. Configure which data types to sync (transactions, balances)
5. The system links accounts and begins fetching data

### Configuration Options

| Option                     | Type    | Min | Default | Description                                                                                  |
| -------------------------- | ------- | --- | ------- | -------------------------------------------------------------------------------------------- |
| `update_frequency_minutes` | Integer | 360 | 1440    | Update frequency in minutes. GoCardless has strict rate limits (4 requests/day per account). |

### Manual Operations

- **Refresh Data**: Trigger a manual fetch through the integration settings
- **Migration**: Use the migration feature to backfill historical transaction data (up to 24 months)

## API Reference

The integration uses the GoCardless Bank Account Data API:

| Endpoint                             | Method | Description                                                    |
| ------------------------------------ | ------ | -------------------------------------------------------------- |
| `/api/v2/token/new`                  | POST   | Obtain access token                                            |
| `/api/v2/institutions`               | GET    | List available banks by country                                |
| `/api/v2/agreements/enduser`         | POST   | Create end-user agreement (24 months access, 90 days validity) |
| `/api/v2/requisitions`               | POST   | Create bank connection requisition                             |
| `/api/v2/requisitions/{id}`          | GET    | Get requisition status                                         |
| `/api/v2/accounts/{id}`              | GET    | Get account details                                            |
| `/api/v2/accounts/{id}/balances`     | GET    | Get account balances                                           |
| `/api/v2/accounts/{id}/transactions` | GET    | Get account transactions                                       |

## Troubleshooting

### Common Issues

**Institutions Not Loading**

- Verify API credentials are correct in environment variables
- Check network connectivity to GoCardless API
- Ensure the country code is supported by GoCardless

**Authentication Failures**

- Verify the redirect URI matches your GoCardless dashboard configuration
- Check that the requisition has not expired (90-day validity)
- Ensure the end-user agreement is still valid

**Data Not Fetching**

- Check that accounts are properly linked (requisition status)
- Verify API rate limits have not been exceeded (4 requests/day per account)
- Review Laravel logs for detailed error information
- Ensure update frequency respects rate limits (minimum 360 minutes recommended)

**Rate Limit Errors**

- GoCardless enforces strict rate limits: 10 transaction calls and 10 balance calls per day per account
- Increase `update_frequency_minutes` to reduce API calls
- Consider using the default 1440 minutes (24 hours) for most use cases

## Related Documentation

- [GoCardless Bank Account Data API Documentation](https://gocardless.com/bank-account-data/)
- [PSD2 Open Banking Overview](https://gocardless.com/guides/posts/open-banking-psd2/)
