<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add a "Wallet" tender so POS sales can be paid from the customer's wallet.
     * Idempotent: matches the seeding style in create_payment_methods_table.
     */
    public function up(): void
    {
        DB::table('payment_methods')->updateOrInsert(
            ['id' => 8],
            ['id' => 8, 'name' => 'Wallet']
        );
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('id', 8)->where('name', 'Wallet')->delete();
    }
};
