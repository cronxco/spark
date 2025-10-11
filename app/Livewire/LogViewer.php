<?php

namespace App\Livewire;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\LoggingService;
use Livewire\Component;

class LogViewer extends Component
{
    public string $type; // 'user', 'group', or 'integration'

    public ?string $entityId = null; // UUID of the user, group, or integration

    public string $date;

    public string $levelFilter = 'all';

    public string $search = '';

    public bool $autoRefresh = false;

    public array $logLines = [];

    public array $availableDates = [];

    protected $queryString = ['date', 'levelFilter', 'search'];

    public function mount(string $type, ?string $entityId = null, ?string $date = null): void
    {
        $this->type = $type;
        $this->entityId = $entityId;
        $this->date = $date ?? now()->format('Y-m-d');

        $this->loadAvailableDates();
        $this->loadLogs();
    }

    public function updatedDate(): void
    {
        $this->loadLogs();
    }

    public function updatedLevelFilter(): void
    {
        $this->loadLogs();
    }

    public function updatedSearch(): void
    {
        $this->loadLogs();
    }

    public function refreshLogs(): void
    {
        $this->loadLogs();
    }

    public function downloadLog()
    {
        $path = $this->getLogPath();

        if (! file_exists($path)) {
            return;
        }

        return response()->download($path);
    }

    public function getLevelBadgeClass(string $level): string
    {
        return match ($level) {
            'debug' => 'badge-ghost',
            'info' => 'badge-info',
            'notice' => 'badge-primary',
            'warning' => 'badge-warning',
            'error' => 'badge-error',
            'critical', 'alert', 'emergency' => 'badge-error',
            default => 'badge-ghost',
        };
    }

    public function render()
    {
        return view('livewire.log-viewer');
    }

    protected function loadAvailableDates(): void
    {
        $files = $this->getLogFiles();

        // Extract dates from filenames
        $dates = [];
        foreach ($files as $file) {
            if (preg_match('/_(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                $dates[] = $matches[1];
            }
        }

        $this->availableDates = array_unique($dates);
        rsort($this->availableDates); // Most recent first
    }

    protected function getLogFiles(): array
    {
        return match ($this->type) {
            'user' => LoggingService::getUserLogFiles($this->getUser()),
            'group' => LoggingService::getGroupLogFiles($this->getGroup()),
            'integration' => LoggingService::getIntegrationLogFiles($this->getIntegration()),
            default => [],
        };
    }

    protected function getLogPath(): string
    {
        return match ($this->type) {
            'user' => LoggingService::getUserLogPath($this->getUser(), $this->date),
            'group' => LoggingService::getGroupLogPath($this->getGroup(), $this->date),
            'integration' => LoggingService::getIntegrationLogPath($this->getIntegration(), $this->date),
            default => '',
        };
    }

    protected function loadLogs(): void
    {
        $path = $this->getLogPath();

        if (! file_exists($path)) {
            $this->logLines = [];

            return;
        }

        $content = file_get_contents($path);
        $lines = explode("\n", $content);

        // Parse log lines
        $parsedLines = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parsed = $this->parseLogLine($line);
            if ($parsed) {
                $parsedLines[] = $parsed;
            }
        }

        // Apply filters
        $parsedLines = $this->applyFilters($parsedLines);

        $this->logLines = $parsedLines;
    }

    protected function parseLogLine(string $line): ?array
    {
        // Match Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/i', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => strtolower($matches[2]),
                'message' => $matches[3],
                'raw' => $line,
            ];
        }

        return null;
    }

    protected function applyFilters(array $lines): array
    {
        // Filter by level
        if ($this->levelFilter !== 'all') {
            $lines = array_filter($lines, fn ($line) => $line['level'] === $this->levelFilter);
        }

        // Filter by search term
        if (! empty($this->search)) {
            $searchLower = strtolower($this->search);
            $lines = array_filter($lines, function ($line) use ($searchLower) {
                return str_contains(strtolower($line['message']), $searchLower) ||
                       str_contains(strtolower($line['raw']), $searchLower);
            });
        }

        return array_values($lines);
    }

    protected function getUser(): User
    {
        if ($this->entityId) {
            return User::findOrFail($this->entityId);
        }

        return auth()->user();
    }

    protected function getGroup(): IntegrationGroup
    {
        return IntegrationGroup::findOrFail($this->entityId);
    }

    protected function getIntegration(): Integration
    {
        return Integration::findOrFail($this->entityId);
    }
}
