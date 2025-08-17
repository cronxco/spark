<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoCardlessAdminController extends Controller
{
    /**
     * Show the admin UI for GoCardless agreements and requisitions
     */
    public function index()
    {
        try {
            $agreements = $this->getAgreements();
            $requisitions = $this->getRequisitions();
            
            return view('admin.gocardless.index', compact('agreements', 'requisitions'));
        } catch (\Throwable $e) {
            Log::error('GoCardless admin UI failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return view('admin.gocardless.index', [
                'agreements' => [],
                'requisitions' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete an agreement
     */
    public function deleteAgreement(Request $request, string $agreementId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Accept' => 'application/json',
            ])->delete(config('services.gocardless.api_base') . '/agreements/enduser/' . $agreementId . '/');

            if ($response->successful()) {
                Log::info('GoCardless agreement deleted successfully', [
                    'agreement_id' => $agreementId,
                    'response' => $response->json(),
                ]);
                
                return redirect()->route('admin.gocardless.index')
                    ->with('success', "Agreement {$agreementId} deleted successfully");
            }

            Log::error('Failed to delete GoCardless agreement', [
                'agreement_id' => $agreementId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return redirect()->route('admin.gocardless.index')
                ->with('error', "Failed to delete agreement: " . $response->body());

        } catch (\Throwable $e) {
            Log::error('Exception deleting GoCardless agreement', [
                'agreement_id' => $agreementId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('admin.gocardless.index')
                ->with('error', "Exception deleting agreement: " . $e->getMessage());
        }
    }

    /**
     * Delete a requisition
     */
    public function deleteRequisition(Request $request, string $requisitionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Accept' => 'application/json',
            ])->delete(config('services.gocardless.api_base') . '/requisitions/' . $requisitionId . '/');

            if ($response->successful()) {
                Log::info('GoCardless requisition deleted successfully', [
                    'requisition_id' => $requisitionId,
                    'response' => $response->json(),
                ]);
                
                return redirect()->route('admin.gocardless.index')
                    ->with('success', "Requisition {$requisitionId} deleted successfully");
            }

            Log::error('Failed to delete GoCardless requisition', [
                'requisition_id' => $requisitionId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return redirect()->route('admin.gocardless.index')
                ->with('error', "Failed to delete requisition: " . $response->body());

        } catch (\Throwable $e) {
            Log::error('Exception deleting GoCardless requisition', [
                'requisition_id' => $requisitionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('admin.gocardless.index')
                ->with('error', "Exception deleting requisition: " . $e->getMessage());
        }
    }

    /**
     * Get all agreements from GoCardless API
     */
    protected function getAgreements(): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Accept' => 'application/json',
        ])->get(config('services.gocardless.api_base') . '/agreements/enduser/');

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch agreements: ' . $response->body());
        }

        $data = $response->json();
        return $data['results'] ?? [];
    }

    /**
     * Get all requisitions from GoCardless API
     */
    protected function getRequisitions(): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Accept' => 'application/json',
        ])->get(config('services.gocardless.api_base') . '/requisitions/');

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch requisitions: ' . $response->body());
        }

        $data = $response->json();
        return $data['results'] ?? [];
    }

    /**
     * Get access token from GoCardless API
     */
    protected function getAccessToken(): string
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(config('services.gocardless.api_base') . '/token/new/', [
            'secret_id' => config('services.gocardless.secret_id'),
            'secret_key' => config('services.gocardless.secret_key'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to get access token: ' . $response->body());
        }

        $data = $response->json();
        return $data['access'] ?? '';
    }
}
