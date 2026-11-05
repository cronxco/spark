<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Google Calendar events were written with service 'google_calendar' while the
 * plugin, its integrations and its groups use 'google-calendar'. The plugin now
 * writes its identifier; this brings the existing rows in line. A value-only
 * change, with no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')->where('service', 'google_calendar')->update(['service' => 'google-calendar']);
    }

    public function down(): void
    {
        DB::table('events')->where('service', 'google-calendar')->update(['service' => 'google_calendar']);
    }
};
