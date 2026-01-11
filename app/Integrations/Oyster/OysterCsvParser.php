<?php

namespace App\Integrations\Oyster;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class OysterCsvParser
{
    private OysterTransportModeDetector $modeDetector;

    public function __construct()
    {
        $this->modeDetector = new OysterTransportModeDetector;
    }

    /**
     * Parse CSV content and extract journeys and non-journey entries
     *
     * CSV columns:
     * Date, Start Time, End Time, Journey/Action, Charge, Credit, Balance, Note
     */
    public function parse(string $csvContent): array
    {
        $lines = explode("\n", trim($csvContent));

        // Skip empty lines at the start
        while (! empty($lines) && empty(trim($lines[0]))) {
            array_shift($lines);
        }

        if (empty($lines)) {
            Log::warning('Oyster CSV: Empty content');

            return [
                'journeys' => [],
                'non_journeys' => [],
            ];
        }

        // Parse header
        $headerLine = array_shift($lines);
        $header = str_getcsv($headerLine);

        // Normalize header names (trim whitespace)
        $header = array_map('trim', $header);

        $journeys = [];
        $nonJourneys = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $row = str_getcsv($line);

            // Skip if row doesn't have enough columns
            if (count($row) < count($header)) {
                Log::debug('Oyster CSV: Skipping incomplete row', [
                    'line' => $lineNum + 2, // +2 because 1-indexed and we removed header
                    'columns' => count($row),
                ]);

                continue;
            }

            $data = array_combine($header, array_slice($row, 0, count($header)));

            try {
                $parsed = $this->parseRow($data);

                if ($parsed['is_journey']) {
                    $journeys[] = $parsed;
                } else {
                    $nonJourneys[] = $parsed;
                }
            } catch (Exception $e) {
                Log::warning('Oyster CSV: Failed to parse row', [
                    'line' => $lineNum + 2,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ]);
            }
        }

        return [
            'journeys' => $journeys,
            'non_journeys' => $nonJourneys,
        ];
    }

    /**
     * Parse a single row of CSV data
     */
    private function parseRow(array $data): array
    {
        $journeyAction = $data['Journey/Action'] ?? '';

        // Check if this is a non-journey entry
        if ($this->modeDetector->isNonJourney($journeyAction)) {
            return $this->parseNonJourney($data, $journeyAction);
        }

        // Parse as journey
        return $this->parseJourney($data, $journeyAction);
    }

    /**
     * Parse a journey entry
     */
    private function parseJourney(array $data, string $journeyAction): array
    {
        $parsed = $this->modeDetector->parseStations($journeyAction);

        $date = $data['Date'] ?? '';
        $startTime = $data['Start Time'] ?? '';
        $endTime = ! empty($data['End Time'] ?? '') ? $data['End Time'] : null;

        // Parse datetime
        $startDateTime = $this->parseDateTime($date, $startTime);
        $endDateTime = ! empty($endTime) ? $this->parseDateTime($date, $endTime) : null;

        // Handle journeys that cross midnight
        if ($endDateTime && $endDateTime->lt($startDateTime)) {
            $endDateTime = $endDateTime->copy()->addDay();
        }

        return [
            'is_journey' => true,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_datetime' => $startDateTime,
            'end_datetime' => $endDateTime,
            'origin' => $parsed['origin'],
            'destination' => $parsed['destination'],
            'transport_mode' => $parsed['mode'],
            'charge' => $this->parseAmount($data['Charge'] ?? ''),
            'credit' => $this->parseAmount($data['Credit'] ?? ''),
            'balance' => $this->parseAmount($data['Balance'] ?? ''),
            'note' => trim($data['Note'] ?? ''),
            'raw_action' => $journeyAction,
        ];
    }

    /**
     * Parse a non-journey entry (top-up, season ticket, etc.)
     */
    private function parseNonJourney(array $data, string $journeyAction): array
    {
        $actionType = $this->modeDetector->getNonJourneyType($journeyAction);

        $date = $data['Date'] ?? '';
        $time = $data['Start Time'] ?? '';

        // Extract station name from entries like "Topped-up on touch in, Victoria [National Rail]"
        $station = null;
        if (preg_match('/,\s*(.+)$/', $journeyAction, $matches)) {
            $station = trim($matches[1]);
            // Clean up mode annotations
            $station = preg_replace('/\s*\[.*?\]\s*/', '', $station);
            // Clean up platform info like "(platforms 9-19)"
            $station = preg_replace('/\s*\(.*?\)\s*$/', '', $station);
            $station = trim($station);
        }

        return [
            'is_journey' => false,
            'action_type' => $actionType,
            'date' => $date,
            'time' => $time,
            'datetime' => $this->parseDateTime($date, $time),
            'station' => $station,
            'charge' => $this->parseAmount($data['Charge'] ?? ''),
            'credit' => $this->parseAmount($data['Credit'] ?? ''),
            'balance' => $this->parseAmount($data['Balance'] ?? ''),
            'note' => trim($data['Note'] ?? ''),
            'raw_action' => $journeyAction,
        ];
    }

    /**
     * Parse date and time strings into Carbon instance
     *
     * Date format: DD-MMM-YYYY (e.g., "28-Nov-2025")
     * Time format: HH:MM (e.g., "17:47")
     */
    private function parseDateTime(string $date, string $time): ?Carbon
    {
        if (empty($date) || empty($time)) {
            return null;
        }

        try {
            // Parse date (DD-MMM-YYYY)
            $dateStr = "{$date} {$time}";

            return Carbon::createFromFormat('d-M-Y H:i', $dateStr, 'Europe/London');
        } catch (Exception $e) {
            Log::warning('Oyster CSV: Failed to parse datetime', [
                'date' => $date,
                'time' => $time,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse amount string to float (in pounds)
     *
     * Handles formats like ".00", "2.80", "15.00"
     */
    private function parseAmount(?string $amount): ?float
    {
        if (empty($amount)) {
            return null;
        }

        // Remove currency symbols and whitespace
        $cleaned = preg_replace('/[^\d.-]/', '', trim($amount));

        if (empty($cleaned) || $cleaned === '.') {
            return null;
        }

        $value = (float) $cleaned;

        // Return null for zero amounts (TfL uses .00 for season ticket journeys)
        return $value > 0 ? $value : null;
    }
}
