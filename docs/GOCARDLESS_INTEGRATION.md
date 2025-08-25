# GoCardless Bank Account Data API Integration

This integration connects to bank accounts via the GoCardless Bank Account Data API, providing access to account balances, transactions, and account details across European Economic Area (EEA) countries.

## Overview

The GoCardless Bank Account Data API allows you to:
- Connect to user bank accounts through PSD2-regulated APIs
- Access up to 24 months of transaction history
- Maintain continuous access for up to 90 days
- Retrieve account balances, details, and transactions
- Support multiple European countries

## Setup

### 1. Environment Configuration

Add the following to your `.env` file:

```bash
# GoCardless Bank Account Data API
GOCARDLESS_SECRET_ID=your_secret_id_here
GOCARDLESS_SECRET_KEY=your_secret_key_here
GOCARDLESS_COUNTRY=GB
GOCARDLESS_INSTITUTION_ID=optional_pre_selected_bank
GOCARDLESS_REDIRECT_URI=https://yourdomain.com/integrations/gocardless/callback
```

### 2. Get GoCardless Credentials

1. Sign up for a GoCardless account at [gocardless.com](https://gocardless.com)
2. Access the Bank Account Data portal
3. Obtain your user secrets (secret_id and secret_key)
4. Configure your redirect URI

### 3. Service Configuration

The integration is automatically configured through `config/services.php`:

```php
'gocardless' => [
    'secret_id' => env('GOCARDLESS_SECRET_ID'),
    'secret_key' => env('GOCARDLESS_SECRET_KEY'),
    'country' => env('GOCARDLESS_COUNTRY', 'GB'),
    'institution_id' => env('GOCARDLESS_INSTITUTION_ID'),
    'redirect' => env('GOCARDLESS_REDIRECT_URI'),
],
```

## Onboarding Flow

The integration follows a multi-step onboarding process:

1. **Initialize Integration**: User clicks "Add Instance" → creates integration group
2. **Bank Selection**: User selects their bank from available institutions
3. **Authentication**: User is redirected to GoCardless for bank authentication
4. **Callback**: User returns with authorization, requisition is linked
5. **Instance Configuration**: User configures specific instances (transactions, balances)

### Bank Selection

Users can select from available banks in their country. The system fetches institutions directly from the GoCardless API and presents them in a dropdown.

### OAuth Flow

The integration uses GoCardless's requisition-based flow:
1. Creates an end-user agreement (24 months access, 90 days validity)
2. Creates a requisition for the selected institution
3. Redirects user to GoCardless for authentication
4. User authorizes access to their bank account
5. GoCardless redirects back with requisition reference
6. System verifies requisition status and links accounts

## Data Model

### Events

The integration creates events for financial activities:

- **Transaction Events**: `made_transaction` with transaction details
- **Balance Events**: `had_balance` with current account balance

### Event Objects

- **Bank Account**: Represents the connected bank account
- **Counterparty**: Represents transaction counterparties (creditors/debtors)
- **Day**: Temporal context for balance events

### Blocks

- **Balance Snapshots**: Store current account balances

## Instance Types

### 1. Transactions (`transactions`)
- Fetches recent transactions from connected accounts
- Processes transaction metadata (amounts, dates, descriptions)
- Categorizes transactions based on bank transaction codes

### 2. Balances (`balances`)
- Fetches current account balances
- Creates balance events and blocks
- Updates on each fetch cycle

### 3. Accounts (`accounts`)
- Master instance type for account management
- Stores account details and metadata

## Data Fetching

### Regular Updates

The integration fetches data based on configured update frequencies:
- Balances: Current account balances
- Transactions: Recent transaction history

### Migration Support

For historical data backfill, the integration supports:
- Rolling window fetching for transactions
- Batch processing of large datasets
- Progress tracking and error handling

## API Endpoints

The integration uses the following GoCardless API endpoints:

- `POST /api/v2/token/new` - Get access token
- `GET /api/v2/institutions` - List available banks
- `POST /api/v2/agreements/enduser` - Create end-user agreement
- `POST /api/v2/requisitions` - Create bank connection
- `GET /api/v2/requisitions/{id}` - Get requisition status
- `GET /api/v2/accounts/{id}` - Get account details
- `GET /api/v2/accounts/{id}/balances` - Get account balances
- `GET /api/v2/accounts/{id}/transactions` - Get account transactions

## Error Handling

The integration includes comprehensive error handling:

- **API Failures**: Logs detailed error information
- **Authentication Issues**: Handles token expiration and refresh
- **Rate Limiting**: Implements backoff strategies
- **Network Issues**: Graceful degradation and retry logic

## Testing

### Offline Testing

The integration supports offline testing through:
- Mock responses for API calls
- Test environment configurations
- Isolated test data

### Debug Routes

A debug route is available in local environments:
- `/debug/gocardless-test` - Test API connectivity and credentials

## Security Considerations

- **Token Management**: Access tokens are obtained per-request, not stored
- **User Isolation**: Each user's data is properly isolated
- **Secure Redirects**: OAuth flow uses secure redirect URIs
- **Data Encryption**: Sensitive data is encrypted in transit

## Troubleshooting

### Common Issues

1. **Institutions Not Loading**
   - Verify API credentials are correct
   - Check network connectivity
   - Ensure country code is supported

2. **Authentication Failures**
   - Verify redirect URI configuration
   - Check requisition status
   - Ensure end-user agreement is valid

3. **Data Not Fetching**
   - Check account linking status
   - Verify API permissions
   - Review error logs for details

### Debug Steps

1. Check the debug route: `/debug/gocardless-test`
2. Review Laravel logs for detailed error information
3. Verify environment variable configuration
4. Test API connectivity directly

## Migration from Nordigen

This integration replaces the previous Nordigen package with direct GoCardless API calls:

- **Removed**: `nordigen/nordigen-php` dependency
- **Updated**: Configuration keys and environment variables
- **Improved**: Error handling and user experience
- **Enhanced**: Direct API integration without third-party package

## Future Enhancements

Potential improvements for future versions:

1. **Multi-Currency Support**: Enhanced currency handling
2. **Advanced Categorization**: ML-based transaction categorization
3. **Webhook Support**: Real-time data updates
4. **Enhanced Analytics**: Financial insights and reporting
5. **Mobile Optimization**: Improved mobile onboarding experience


