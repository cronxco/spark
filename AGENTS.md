# Repository Guidelines

## Project Structure & Module Organization

Spark is a Laravel 12 and Livewire 3 application. Core PHP code lives in `app/`, with integration plugins under `app/Integrations/`, console commands in `app/Console/Commands/`, and helpers in `app/Support/`. Routes are split by surface in `routes/web.php`, `routes/api.php`, `routes/mobile.php`, and related files. Database migrations, factories, and seeders are in `database/`. Frontend entry points are in `resources/js/` and `resources/css/`, built by Vite into `public/`. Tests are in `tests/Unit/` and `tests/Feature/`. Architecture and integration notes are in `docs/Architecture/` and `docs/Integrations/`.

## Build, Test, and Development Commands

Use Laravel Sail for local development unless a task explicitly targets the host environment.

- `sail up -d`: start the application services.
- `composer dev`: run Laravel server, Horizon, Pail logs, and Vite together.
- `sail npm run dev`: start the Vite development server.
- `sail npm run build`: build production frontend assets.
- `sail artisan migrate`: apply database migrations.
- `sail artisan test`: run the full test suite.
- `sail artisan test --filter TestName`: run one test or filtered group.
- `sail bin duster fix`: format and fix PHP style issues.
- `sail bin duster lint`: check PHP style without changing files.

## Coding Style & Naming Conventions

PHP targets 8.4 and follows Laravel conventions with PSR-4 namespaces under `App\\`. Use 4-space indentation for PHP and descriptive class names, for example `MediaDeduplicationService` or `FetchSpotifyData`. Prefer existing framework patterns. JavaScript and CSS are formatted with Prettier through lint-staged; avoid unrelated formatting churn.

## Testing Guidelines

PHPUnit is configured in `phpunit.xml` with separate Unit and Feature suites. Put focused service tests in `tests/Unit/` and request, integration, or database behavior tests in `tests/Feature/`. Name tests after the behavior under test, using existing patterns such as `CurrencyConversionServiceTest` or `TransactionLinkingTest`. The test environment uses PostgreSQL (`DB_CONNECTION=pgsql`), array cache/session drivers, and synchronous queues.

## Commit & Pull Request Guidelines

Recent commits use gitmoji-prefixed subjects, for example `:sparkles: New endpoints (#785)` and `:arrow_up_small: Update dependency axios to v1.15.2`. Keep commits short and meaningful because release automation depends on gitmoji. Branch new work from `dev`, not `main`. Pull requests should include a concise description, linked references when relevant, test results, and screenshots for visible UI changes.

## Agent-Specific Instructions

Read `CLAUDE.md` and the relevant `docs/Architecture/` page before changing shared data model, integration, media, queue, or task pipeline behavior. Do not overwrite unrelated local changes; this repository may have user edits in progress.
