<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\ConfigurationAudit;
use Illuminate\Console\Command;

class ConfigAudit extends Command
{
    protected $signature = 'config:audit {--json : Emit a machine-readable, redacted report}';

    protected $description = 'Audit explicit configuration and potentially unsafe defaults without printing secrets';

    public function handle(ConfigurationAudit $audit): int
    {
        $report = $audit->run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Configuration audit (no values are shown)');
        $this->table(['Total', 'Explicit', 'Default or unset', 'Errors', 'Warnings'], [[
            $report['summary']['total'],
            $report['summary']['explicit'],
            $report['summary']['default_or_unset'],
            $report['summary']['errors'],
            $report['summary']['warnings'],
        ]]);

        if ($report['findings']) {
            $this->table(['Severity', 'Variable', 'Finding'], array_map(
                fn ($finding) => array_values($finding),
                $report['findings']
            ));
        }

        $this->table(['Variable', 'Source'], array_map(
            fn ($variable) => array_values($variable),
            $report['variables']
        ));

        return $report['summary']['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
