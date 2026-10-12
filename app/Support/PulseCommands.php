<?php

namespace App\Support;

/**
 * The artisan commands that can be run from the Pulse page.
 *
 * Only commands listed here can be run, and only with the arguments listed
 * here. `preview` is the read-only form (null when the command has none) and
 * `apply` the form that changes data (null when it stays console-only).
 * `{user}` is replaced with the viewed tenant's id.
 */
class PulseCommands
{
    public const OURA_JOB = 'job:oura-value-mappings';

    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     scope: string,
     *     preview: ?array<string, mixed>,
     *     apply: ?array<string, mixed>,
     *     sensitive: bool,
     *     note: ?string,
     * }>
     */
    public static function all(): array
    {
        $def = fn (string $label, string $description, ?array $preview, ?array $apply, string $scope = 'all users', bool $sensitive = false, ?string $note = null): array => [
            'label' => $label,
            'description' => $description,
            'preview' => $preview,
            'apply' => $apply,
            'scope' => $scope,
            'sensitive' => $sensitive,
            'note' => $note,
        ];

        return [
            'task-pipeline:migrate-executions' => $def('Migrate task executions', 'Create task execution rows for records that predate the pipeline.', ['--dry-run' => true], []),
            'task-pipeline:populate-initial-state' => $def('Populate initial task state', 'Fill initial task execution state for existing records.', ['--dry-run' => true], []),
            'knowledge:tasks-backfill' => $def('Backfill knowledge tasks', 'Value-only backfill of task fields; changes can be rolled back from the console.', ['--user' => '{user}'], ['--user' => '{user}', '--apply' => true], 'you'),
            'knowledge:reprocess-missing-summaries' => $def('Reprocess missing summaries', 'Queue summaries for fetch and newsletter events that have none.', ['--dry-run' => true], []),
            'newsletter:backfill-issue-content' => $def('Backfill newsletter issue content', 'Queue newsletter events whose issue content is missing.', ['--dry-run' => true], []),
            'fetch:repair-subscription-revisions' => $def('Repair fetch subscription revisions', 'Requeue fetches for subscriptions with broken revisions.', ['--dry-run' => true], []),
            'fetch:extract-article-images' => $def('Extract article images', 'Attach article images to bookmarks that lack one.', ['--dry-run' => true, '--user' => '{user}'], ['--user' => '{user}', '--skip-existing' => true], 'you'),
            'media:migrate-external-urls' => $def('Migrate external media URLs', 'Download externally hosted images into the media library.', ['--dry-run' => true, '--user' => '{user}'], ['--user' => '{user}'], 'you'),
            'media:fix-responsive-images' => $def('Generate missing responsive images', 'Create responsive image variants where they are missing.', null, []),
            'blocks:backfill-apple-health-types' => $def('Backfill Apple Health block types', 'Set block types on older Apple Health blocks.', null, ['--force' => true]),
            'relationships:migrate-had-link-to' => $def('Migrate had_link_to relationships', 'Convert legacy link relationships to the current type.', null, ['--force' => true]),
            'notifications:backfill-metadata' => $def('Backfill notification metadata', 'Add missing metadata to stored notifications.', ['--dry-run' => true], []),
            'places:recalculate-visit-counts' => $def('Recalculate place visit counts', 'Recompute visit counts for all places.', ['--dry-run' => true], ['--force' => true]),
            'oyster:backfill-transport-tags' => $def('Backfill Oyster transport tags', 'Tag existing Oyster journeys by transport mode.', null, []),
            'receipt-matching:backfill' => $def('Backfill receipt matches', 'Queue review-only matching attempts for unmatched receipts.', [], ['--dispatch' => true]),
            'gocardless:reconcile-accounts' => $def('Reconcile GoCardless accounts', 'Merge duplicate bank accounts and fix names.', ['--user' => '{user}'], ['--user' => '{user}', '--apply' => true], 'you'),
            'monzo:infer-interest' => $def('Infer Monzo pot interest', 'Plan of interest_earned events inferred from pot snapshots. Applying waits for Will\'s go.', ['--user' => '{user}', '--detail' => true], null, 'you', false, 'Apply from the console once the plan is approved.'),
            'receipts:repair-timezones' => $def('Repair receipt timezones', 'Needs a confirmed IANA timezone, so it stays in the console.', null, null, 'you', false, 'php artisan receipts:repair-timezones --timezone=<tz> --user=<id>'),
            'gocardless:reconcile-pending' => $def('Reconcile GoCardless pending transactions', 'Resolve pending bank transactions against booked ones.', [], ['--apply' => true], 'all users', true),
            'integrations:encrypt-credentials' => $def('Encrypt integration credentials', 'Encrypt credentials still stored in plain text.', ['--dry-run' => true], [], 'all users', true),
            'tokens:revoke-wildcard' => $def('Revoke wildcard tokens', 'Revoke API tokens that carry every ability.', ['--dry-run' => true], [], 'all users', true),
            'activity-log:redact-credentials' => $def('Redact credentials from the activity log', 'Remove secrets that were written into activity log rows.', ['--dry-run' => true], [], 'all users', true),
            self::OURA_JOB => $def('Oura value mappings', 'Store mapped numeric values for stress and resilience on your Oura events.', null, [], 'you'),
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function resolveArguments(array $args, string $tenantId): array
    {
        return array_map(fn ($value) => $value === '{user}' ? $tenantId : $value, $args) + ['--no-interaction' => true];
    }
}
