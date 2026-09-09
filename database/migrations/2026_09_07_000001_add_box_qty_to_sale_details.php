<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_details', 'box_qty')) {
                // How many boxes/cartons this line's quantity was packed into
                // (e.g. quantity 12, box_qty 1). Purely informational.
                $table->decimal('box_qty', 10, 2)->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('box_qty');
        });
    }
};
