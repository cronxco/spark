## Monzo Integration

This integration connects your Monzo account to Spark, creating events and objects for transactions, pots and daily balances. It uses OAuth, runs under Laravel Sail, and leverages Laravel Horizon with a dedicated `migration` queue for historical backfill.

### Installation

1) Dependencies

- Composer includes `willscottuk/monzo-php` and the Spark integration framework.

2) Environment

Add the following to your `.env`:

```
MONZO_CLIENT_ID=your_client_id
MONZO_CLIENT_SECRET=your_client_secret
MONZO_REDIRECT_URI=https://yourapp.test/integrations/monzo/callback
MONZO_SALARY_NAME=
```

3) Config

`config/services.php` includes `monzo` keys and is read by `App\Integrations\Monzo\MonzoPlugin`.

4) Queues (Horizon)

Horizon is configured with a dedicated `migration` queue (see `config/horizon.php`). Start it via Sail:

```
./vendor/bin/sail up -d
./vendor/bin/sail artisan horizon
```

### Onboarding

1) Navigate to `/integrations` and choose Monzo
2) Click “Connect” to start OAuth and approve scopes
3) After callback, create instances for any of:
- transactions
- pots
- balances

Each instance type will ingest its own data set.

### Historical Migration (Backfill)

The migration runs in two clear phases on the `migration` queue, prioritising downloading all historical data before any processing:

1) Seed (runs once at migration start)
- Ensures a single master instance `accounts` exists for the group and seeds Monzo account and pot objects (no events) using `SeedMonzoAccounts`.
- These master objects are shared across all other instances to avoid duplication.

2) Fetch phase
- Transactions: enqueues 89‑day windows going backwards until no data is returned. Windows are recorded in cache for deterministic processing later.
- Balances: records the latest balance date (one cut‑off) in cache.
- Pots: marks as fetched in cache (processing creates a snapshot later).
- The fetch phase stops automatically when a window returns no transactions across all accounts.

3) Processing phase
- Starts automatically when the fetch batch completes.
- Creates one processing job per recorded transaction window, plus a single pots snapshot job and a single balances snapshot job.
- Batch progress is accurate because it reflects the total number of processing jobs.

Trigger manually (typically done during onboarding):

```php
$i = \App\Models\Integration::where('service', 'monzo')->first();
dispatch(new \App\Jobs\Migrations\StartIntegrationMigration($i))
    ->onConnection('redis')->onQueue('migration');
```

Monitor progress at `/horizon`.

### Ongoing Updates (Polling)

`MonzoPlugin::fetchData()` can be invoked by your scheduled “pull” jobs to keep up with recent changes. It fetches:

- Recent transactions (last 7 days)
- Current balance
- Current pots snapshot

### Testing

Run tests:

```
./vendor/bin/sail artisan test
```

Feature tests for Monzo migration live at `tests/Feature/MonzoMigrationTest.php`. They use `Http::fake()` to mock `api.monzo.com` endpoints and assert that events/objects are created correctly for transactions, pots, and balances.

Example snippet:

```php
Http::fake([
    'api.monzo.com/transactions*' => Http::response([
        'transactions' => [[
          'id' => 'tx_1',
          'amount' => -500,
          'currency' => 'GBP',
          'local_amount' => -550,
          'local_currency' => 'EUR',
          'merchant' => [ 'id' => 'merch_1', 'name' => 'Test Store', 'logo' => 'https://example.com/logo.png' ],
        ]],
    ], 200),
]);

dispatch_sync(new ProcessIntegrationPage($integration, [[
    'kind' => 'transactions_window',
    'since' => now()->subDays(1)->toIso8601String(),
    'before' => now()->toIso8601String(),
]], [ 'service' => 'monzo', 'instance_type' => 'transactions' ]));

$event = Event::with('blocks')->where('source_id', 'tx_1')->first();
// Merchant and FX blocks are created
```

### Troubleshooting

- OAuth callback fails: verify `MONZO_REDIRECT_URI` matches the value configured on Monzo developer portal
- Horizon not processing: check `QUEUE_CONNECTION=redis`, Horizon supervisors include `migration` queue, and Redis is available in Sail
- No data created: use `Http::fake()` in tests to validate processing and confirm credentials in development

### References

## Data model: objects, events, blocks, tags

### Objects

- `monzo_account` (concept: `account`, stored on the master `accounts` instance)
  - One per Monzo account (e.g., Current Account, Joint Account, Flex)
  - `metadata.account_id` holds the Monzo account id; full raw account details are stored under `metadata.raw`

- `monzo_pot` (concept: `account`, stored on the master `accounts` instance)
  - One per Monzo Pot
  - `metadata.pot_id` holds the Monzo pot id; `metadata.deleted` is a boolean

- `counterparty` (concept: `counterparty`, stored on the master `accounts` instance)
  - Created only when the B‑party is not a pot
  - Title is the merchant name (if available) or description fallback
  - `metadata.merchant_id`, `metadata.category`, `metadata.currency`

- `day` (concept: `day`, type: `day`, stored per integration)
  - Synthetic object used as the target for daily balance events
  - Title is the date (`YYYY-MM-DD`)

### Events

- Transaction events (domain: `money`, service: `monzo`)
  - `action`: `debit` if `amount < 0`, else `credit`
  - `actor`: `monzo_account` object (the account that made/received the transaction)
  - `target`: either a `monzo_pot` object when the counterparty is a Pot, or a `counterparty` object otherwise
  - `value`: integer cents (e.g., 128 for £1.28)
  - `value_multiplier`: `100` (money is encoded as integer cents + multiplier)
  - `value_unit`: `GBP`
  - `event_metadata`: `{ category, scheme, notes }`
  - `source_id`: Monzo transaction id

- Balance events (domain: `money`, service: `monzo`)
  - `action`: `had_balance`
  - `actor`: `monzo_account` object
  - `target`: `day` object for the event date
  - `value`: integer cents; `value_multiplier`: `100`; `value_unit`: `GBP`
  - `event_metadata`: `{ spend_today, snapshot_date? }`
  - `source_id`: `monzo_balance_{account_id}_{YYYY-MM-DD}`

### Blocks

Blocks enrich events with contextual content and values for better UI rendering. The Monzo integration adds the following blocks:

- Merchant
  - For card transactions with `merchant` data (using `expand[]=merchant`), adds name, merchant category, and formatted address. Includes `media_url` for the merchant logo when present.

- FX
  - When a transaction contains different `local_currency` and `currency`, adds a breakdown like: `Local: 12.34 EUR → 10.56 GBP (rate 0.856927)`.

- Pot Transfer
  - For `uk_retail_pot` scheme transactions, shows transfer direction (To/From) and pot name when resolved.

- Joint Account
  - Indicates the transaction occurred on a joint account.

- Bank Transfer
  - For Faster Payments (`payport_faster_payments`), shows counterparty name and sort-code/account-number when available.

- Virtual Card
  - Indicates a virtual card was used.

- Spend Today (Balance events)
  - On daily balance events, adds a block with value equal to `abs(spend_today)` in integer cents (`value_multiplier=100`).

- Balance Change (Balance events)
  - Compares the latest balance against the previous day’s balance for the same account. Adds a block showing direction (Up/Down) and absolute delta in integer cents (`value_multiplier=100`).

### Tags

- None currently created by this integration. If you need tags (e.g., by category or currency) they can be added later in processing.

## Implementation details

- Master `accounts` instance
  - Only seeds shared account/pot objects; never creates events.
  - All other instances (`transactions`, `pots`, `balances`) refer to these master objects.

- Idempotency and de‑duplication
  - Database unique index on `events (integration_id, source_id)` ensures event idempotency and prevents duplicates under race.
  - Counterparties link to existing `monzo_pot` objects when the B‑party is a Pot; only fallback to `counterparty` objects for non‑pot B‑parties.

- Money encoding
  - All monetary values are stored as integer cents with `value_multiplier=100` and `value_unit=GBP` to avoid float errors.

- Migration caches
  - `monzo:migration:{integration_id}:tx_windows` — array of 89‑day windows to process
  - `monzo:migration:{integration_id}:fetched_back_to` — earliest start date fetched by transactions
  - `monzo:migration:{integration_id}:balances_last_date` — last balance date captured

- Updates page
  - Shows a progress bar; indeterminate while fetching, precise during processing
  - “Fetched to” date is shown only while migration is running; hidden at 100%
  - Shows a green “Migrated” pill when complete
  - Auto refreshes every 10 seconds


- Monzo API: `https://docs.monzo.com/`
- Monzo OAuth: `https://developers.monzo.com/apps`
- Laravel Horizon: `https://laravel.com/docs/horizon`

