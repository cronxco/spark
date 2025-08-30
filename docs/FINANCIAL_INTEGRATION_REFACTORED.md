# Financial Integration (Refactored)

The Financial Integration has been refactored to use the existing event-driven architecture instead of custom tables and models. This follows the same pattern used by Monzo, GoCardless, and other integrations.

## Architecture Overview

### Event-Driven Design

- **Financial Accounts** are stored as `EventObject` instances with `concept: 'account'` and `type: 'manual_account'`
- **Balance Updates** are stored as `Event` instances with `service: manual_account'`, `domain: 'money'`, and `action: 'had_balance'`
- **Day Objects** are created as targets for balance events, following the same pattern as Monzo integration

### Data Structure

#### Financial Account Object

```php
EventObject {
    concept: 'account',
    type: 'manual_account',
    title: 'Account Name',
    metadata: {
        name: 'Main Current Account',
        account_type: 'current_account',
        provider: 'Barclays',
        account_number: '12345678',
        sort_code: '20-00-00',
        currency: 'GBP',
        interest_rate: 2.5,
        start_date: '2020-01-01'
    }
}
```

#### Balance Event

```php
Event {
    service: manual_account',
    domain: 'money',
    action: 'had_balance',
    actor_id: 'account-object-id',
    target_id: 'day-object-id',
    value: 1500.00,
    value_unit: 'GBP',
    event_metadata: {
        balance: 1500.00,
        notes: 'Monthly salary received',
        account_name: 'Main Current Account',
        account_type: 'current_account',
        provider: 'Barclays'
    }
}
```

## Implementation Details

### FinancialPlugin Class

The `FinancialPlugin` extends `ManualPlugin` and provides methods for:

- `upsertAccountObject()` - Create or update financial account objects
- `createBalanceEvent()` - Create balance update events
- `getFinancialAccounts()` - Retrieve all financial accounts for a user
- `getBalanceEvents()` - Get balance events for a specific account
- `getLatestBalance()` - Get the most recent balance for an account

### Livewire Components

All components have been refactored to work with the event system:

- **FinancialAccounts** - Lists accounts using `EventObject` queries
- **CreateFinancialAccount** - Creates account objects via the plugin
- **AddBalanceUpdate** - Creates balance events via the plugin

### Data Access Patterns

Instead of direct model relationships, data is accessed through:

1. **Plugin Methods** - Use the plugin's helper methods for common operations
2. **Metadata Access** - Account details are stored in `EventObject.metadata`
3. **Event Queries** - Balance information comes from `Event` queries
4. **Collection Filtering** - Use Laravel collection methods for filtering and pagination

## Benefits of the Refactored System

### Consistency

- Follows the same pattern as all other integrations
- Uses the existing `events` and `objects` tables
- Integrates seamlessly with the dashboard and analytics

### Flexibility

- Financial data appears alongside other integration data
- Can use existing event querying and filtering tools
- Supports the same tagging and categorization system

### Scalability

- No additional database tables required
- Leverages existing indexing and optimization
- Can easily add new financial event types

### Integration

- Works with existing event display components
- Integrates with the updates and notifications system
- Can be analyzed using existing event analytics tools

## Usage Examples

### Creating an Account

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

### Adding a Balance Update

```php
$balanceData = [
    'balance' => 5000.00,
    'date' => '2025-01-27',
    'notes' => 'Year-end bonus received',
];

$balanceEvent = $plugin->createBalanceEvent($integration, $accountObject, $balanceData);
```

### Retrieving Accounts

```php
$accounts = $plugin->getFinancialAccounts($user);
foreach ($accounts as $account) {
    $metadata = $account->metadata;
    echo $metadata['name'] . ' - ' . $metadata['provider'];
}
```

### Getting Balance History

```php
$balanceEvents = $plugin->getBalanceEvents($accountObject);
$latestBalance = $plugin->getLatestBalance($accountObject);
```

## Migration from Custom Tables

The refactored system eliminates the need for:

- `manual_accounts` table
- `financial_balances` table
- `FinancialAccount` model
- `FinancialBalance` model

All data is now stored in the existing `events` and `objects` tables, making the system more consistent and maintainable.

## Testing

The refactored system includes comprehensive tests in `tests/Feature/FinancialIntegrationEventTest.php` that verify:

- Account object creation
- Balance event creation
- Data retrieval methods
- Plugin functionality
- Error handling for unsupported operations

## Future Enhancements

With the event-driven architecture, it's easy to add:

- **Transaction Events** - Track individual transactions
- **Transfer Events** - Track money movements between accounts
- **Interest Events** - Track interest accrual
- **Fee Events** - Track account fees and charges
- **Goal Events** - Track progress toward financial goals

All new event types will automatically integrate with the existing dashboard, analytics, and reporting systems.
