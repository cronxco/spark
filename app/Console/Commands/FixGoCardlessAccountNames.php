<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixGoCardlessAccountNames extends Command
{
    protected $signature = 'gocardless:fix-account-names {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'Fix GoCardless account objects that have placeholder names like "Account XXXXX" and merge duplicates';

    public function handle(): int
    {
        $this->error('Use gocardless:reconcile-accounts --user=<UUID> for a dry-run plan, then add --apply after review.');

        return self::FAILURE;
    }
}
