<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Migrations\MigrateOuraValueMappings;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class MigrationsController extends Controller
{
    /**
     * Show the admin UI for migrations
     */
    public function index()
    {
        $ouraIntegrations = Integration::where('service', 'oura')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.migrations.index', [
            'ouraIntegrations' => $ouraIntegrations,
        ]);
    }

    /**
     * Dispatch Oura value mapping migration
     */
    public function migrateOuraValues(Request $request)
    {
        $request->validate([
            'integration_id' => 'nullable|uuid|exists:integrations,id',
        ]);

        try {
            $integration = null;
            if ($request->filled('integration_id')) {
                $integration = Integration::where('service', 'oura')
                    ->where('id', $request->integration_id)
                    ->firstOrFail();
            }

            MigrateOuraValueMappings::dispatch($integration);

            $message = $integration
                ? "Oura value mapping migration dispatched for integration: {$integration->name}"
                : 'Oura value mapping migration dispatched for all integrations';

            Log::info('Oura value mapping migration dispatched from admin', [
                'integration_id' => $integration?->id,
                'integration_name' => $integration?->name,
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('admin.migrations.index')
                ->with('success', $message);

        } catch (Throwable $e) {
            Log::error('Failed to dispatch Oura value mapping migration', [
                'integration_id' => $request->integration_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('admin.migrations.index')
                ->with('error', 'Failed to dispatch migration: '.$e->getMessage());
        }
    }
}
