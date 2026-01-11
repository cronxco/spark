<?php

namespace App\Integrations\Oyster;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class OysterPdfParser
{
    /**
     * Extract Oyster card number from PDF attachment
     *
     * Card numbers are 12 digits starting with 0: e.g., 061101003531
     * TfL Oyster cards typically start with 06 or similar prefixes
     */
    public function extractCardNumber(string $pdfContent): ?string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();

            // Pattern 1: "Oyster card" or "card number" followed by 12-digit number
            // Look within 50 characters for the number after the keyword
            if (preg_match('/(?:oyster|card\s*(?:number)?)[:\s]*(\d{12})\b/i', $text, $matches)) {
                return $matches[1];
            }

            // Pattern 2: 12-digit number starting with 0 (common Oyster card prefix)
            // Only match if it appears before any journey data (typically in header)
            $headerText = substr($text, 0, 1000); // Look only in first 1000 chars
            if (preg_match('/\b(0[6-9]\d{10})\b/', $headerText, $matches)) {
                return $matches[1];
            }

            // Pattern 3: Any 12-digit number starting with 0 in header area
            if (preg_match('/\b(0\d{11})\b/', $headerText, $matches)) {
                return $matches[1];
            }

            Log::warning('Oyster PDF: Could not extract card number', [
                'text_sample' => substr($text, 0, 500),
            ]);

            return null;
        } catch (Exception $e) {
            Log::warning('Oyster PDF: Failed to parse PDF', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract statement period from PDF
     *
     * Looks for patterns like "Monday, 01 December 2025" or date ranges
     */
    public function extractStatementPeriod(string $pdfContent): ?array
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();

            // Pattern: "Oyster journey statement created on {Day}, {DD} {Month} {YYYY}"
            if (preg_match('/statement\s+created\s+on\s+\w+,?\s*(\d{1,2})\s+(\w+)\s+(\d{4})/i', $text, $matches)) {
                $createdDate = Carbon::createFromFormat(
                    'j F Y',
                    "{$matches[1]} {$matches[2]} {$matches[3]}",
                    'Europe/London'
                );

                // TfL statements are weekly, so statement period is 7 days before created date
                return [
                    'start' => $createdDate->copy()->subDays(7)->startOfDay(),
                    'end' => $createdDate->copy()->subDay()->endOfDay(),
                    'created' => $createdDate,
                ];
            }

            // Pattern: "For Oyster card: {number}" with date range
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s*(?:to|-)\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $text, $matches)) {
                return [
                    'start' => Carbon::createFromFormat('d/m/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}", 'Europe/London')->startOfDay(),
                    'end' => Carbon::createFromFormat('d/m/Y', "{$matches[4]}/{$matches[5]}/{$matches[6]}", 'Europe/London')->endOfDay(),
                    'created' => null,
                ];
            }

            // Pattern: "Dates Covered: {DD}/{MM}/{YYYY} to {DD}/{MM}/{YYYY}"
            if (preg_match('/Dates?\s+Covered:?\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s*(?:to|-)\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $text, $matches)) {
                return [
                    'start' => Carbon::createFromFormat('d/m/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}", 'Europe/London')->startOfDay(),
                    'end' => Carbon::createFromFormat('d/m/Y', "{$matches[4]}/{$matches[5]}/{$matches[6]}", 'Europe/London')->endOfDay(),
                    'created' => null,
                ];
            }

            Log::debug('Oyster PDF: Could not extract statement period', [
                'text_sample' => substr($text, 0, 1000),
            ]);

            return null;
        } catch (Exception $e) {
            Log::warning('Oyster PDF: Failed to extract statement period', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract customer name from PDF if available
     */
    public function extractCustomerName(string $pdfContent): ?string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();

            // Pattern: "Mr/Mrs/Ms/Miss {Name}" at the start
            if (preg_match('/^(Mr|Mrs|Ms|Miss|Dr)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/m', $text, $matches)) {
                return trim($matches[0]);
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get raw text from PDF for debugging
     */
    public function getRawText(string $pdfContent): ?string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($pdfContent);

            return $pdf->getText();
        } catch (Exception $e) {
            return null;
        }
    }
}
