# Financial Integration

A manual account tracking system for managing financial accounts and balance updates using the event-driven architecture.

## Overview

The Financial Integration allows users to manually track financial accounts and their balances over time. It uses the same event-driven architecture as other integrations (Monzo, GoCardless), storing accounts as EventObjects and balance updates as Events. This ensures consistency across the application and enables seamless integration with dashboards and analytics.

## Features

- Manual creation and management of financial accounts
- Support for multiple account types (current, savings, mortgage, investment, credit card, loan, pension)
- Multi-currency support (GBP, USD, EUR)
- Balance history tracking with timestamps and notes
- Negative balance account support for debts and liabilities
- Integration with existing event queries and filtering tools
- Compatibility with Monzo and GoCardless account aggregation

## Setup

### Prerequisites

- Active user account in Spark
- No external API keys or OAuth tokens required

### Configuration

The Financial Integration is a manual plugin that requires no external service configuration. Accounts are created and managed directly through the Spark interface.

### Environment Variables

| Variable | Description                       | Required | Default |
| -------- | --------------------------------- | -------- | ------- |
| N/A      | No environment variables required | -        | -       |

## Data Model

### Instance Types

| Type       | Label           | Description                              |
| ---------- | --------------- | ---------------------------------------- |
| `accounts` | Accounts        | Financial account entities with metadata |
| `balances` | Balance Updates | Point-in-time balance records            |

### Action Types

| Action        | Display Name   | Icon               | Description                 | Value Unit |
| ------------- | -------------- | ------------------ | --------------------------- | ---------- |
| `had_balance` | Balance Update | `o-currency-pound` | Account balance was updated | GBP        |

### Block Types

No block types are defined for this integration.

### Object Types

| Type             | Display Name   | Icon            | Description                          |
| ---------------- | -------------- | --------------- | ------------------------------------ |
| `manual_account` | Manual Account | `o-credit-card` | A manually entered financial account |
| `day`            | Day            | `o-calendar`    | A calendar day used as event target  |

## Usage

### Connecting

1. Navigate to the Financial Accounts section in Spark
2. Create a new manual account with required details
3. Add balance updates as needed to track account history

### Configuration Options

| Option                | Type    | Description                                         | Required | Default |
| --------------------- | ------- | --------------------------------------------------- | -------- | ------- |
| `account_type`        | select  | Type of account (current, savings, mortgage, etc.)  | Yes      | -       |
| `provider`            | text    | Bank or financial institution name                  | Yes      | -       |
| `account_number`      | text    | Account number or identifier                        | No       | -       |
| `sort_code`           | text    | Sort code for UK bank accounts                      | No       | -       |
| `currency`            | select  | Currency for the account (GBP, USD, EUR)            | Yes      | GBP     |
| `interest_rate`       | number  | Annual interest rate percentage                     | No       | -       |
| `start_date`          | date    | Date account was opened                             | No       | -       |
| `is_negative_balance` | boolean | Enable for accounts where higher balances are worse | No       | false   |

### Manual Operations

**Creating an Account:**

```php
$plugin = new FinancialPlugin();
$accountData = [
    'name' => 'Savings Account',
    'account_type' => 'savings_account',
    'provider' => 'HSBC',
    'currency' => 'GBP',
    'interest_rate' => 3.2,
];

$accountObject = $plugin->upsertAccountObject($integration, $accountData);
```

**Adding a Balance Update:**

```php
$balanceData = [
    'balance' => 5000.00,
    'date' => '2025-01-27',
    'notes' => 'Year-end bonus received',
];

$balanceEvent = $plugin->createBalanceEvent($integration, $accountObject, $balanceData);
```

**Retrieving Accounts:**

```php
// Get all financial accounts (manual, Monzo, GoCardless) excluding archived
$accounts = $plugin->getFinancialAccounts($user);

// Get only manual accounts
$manualAccounts = $plugin->getManualFinancialAccounts($user);

// Get all accounts including archived
$allAccounts = $plugin->getAllFinancialAccounts($user);
```

**Getting Balance History:**

```php
$balanceEvents = $plugin->getBalanceEvents($accountObject);
$latestBalance = $plugin->getLatestBalance($accountObject);

// For pagination
$query = $plugin->getBalanceEventsQuery($accountObject);
```

## Troubleshooting

### Common Issues

| Issue                   | Cause                                     | Solution                                                                    |
| ----------------------- | ----------------------------------------- | --------------------------------------------------------------------------- |
| Account not appearing   | Account may be marked as deleted/archived | Check metadata for `deleted` flag                                           |
| Balance not updating    | Duplicate source_id for same date         | Each account can only have one balance per day                              |
| Currency mismatch       | Account currency differs from displayed   | Verify account metadata currency setting                                    |
| Missing balance history | Events queried with wrong service         | Balance events support `manual_account`, `monzo`, and `gocardless` services |

## Related Documentation

- [CLAUDE.md](/home/user/spark/CLAUDE.md) - Integration Plugin System architecture
- [app/Integrations/Financial/FinancialPlugin.php](/home/user/spark/app/Integrations/Financial/FinancialPlugin.php) - Plugin implementation
- [tests/Feature/FinancialIntegrationEventTest.php](/home/user/spark/tests/Feature/FinancialIntegrationEventTest.php) - Integration tests
