<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('addresses')
            ->whereNull('address_type')
            ->whereIn('user_id', DB::table('vendors')->select('user_id'))
            ->update(['address_type' => 'fiscal_address']);
    }

    public function down(): void
    {
        // Not reversible — cannot distinguish backfilled from intentionally set
    }
};
