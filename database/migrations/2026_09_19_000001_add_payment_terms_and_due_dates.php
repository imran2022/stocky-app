<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'default_payment_term_days')) {
                // Level 1 of the Payment Terms hierarchy (System Default).
                // Days until a credit sale is due when the customer has no
                // payment_term_days of their own. 7 matches the shipped default.
                $table->unsignedInteger('default_payment_term_days')->default(7)->after('enable_box_qty');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'payment_term_days')) {
                // Level 2 of the Payment Terms hierarchy (Customer Default).
                // NULL = no override, fall back to the system default.
                $table->unsignedInteger('payment_term_days')->nullable()->after('credit_limit');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'payment_term_days')) {
                // Level 3 (Invoice/Sales Level) — the term actually resolved and
                // used for THIS invoice at the time it was created/edited, so a
                // later change to the customer's or system's default term never
                // silently changes an already-issued invoice's due date.
                $table->unsignedInteger('payment_term_days')->nullable()->after('discount_from_points');
            }
            if (! Schema::hasColumn('sales', 'due_date')) {
                // Derived: sale date + payment_term_days. Stored (not computed
                // on read) so it can be indexed/filtered for an Overdue list.
                $table->date('due_date')->nullable()->after('payment_term_days')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_term_days', 'due_date']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('payment_term_days');
        });
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('default_payment_term_days');
        });
    }
};
