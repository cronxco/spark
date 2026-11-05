<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        // Seed admins from the already-trusted Horizon allow-list so the
        // existing operator(s) don't lose access to the admin panel. Set
        // HORIZON_ALLOWED_EMAILS (or flip is_admin manually) to grant access.
        $allowed = array_filter((array) config('horizon.allowed_emails', []));

        if (! empty($allowed)) {
            DB::table('users')->whereIn('email', $allowed)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
