<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'invoice_number')) {
                $table->string('invoice_number', 40)->nullable()->after('charge_to_account');
            }
            if (! Schema::hasColumn('shipments', 'amount')) {
                $table->decimal('amount', 10, 2)->nullable()->after('invoice_number');
            }
            if (! Schema::hasColumn('shipments', 'invoiced_at')) {
                $table->timestamp('invoiced_at')->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $cols = array_filter(['invoice_number', 'amount', 'invoiced_at'], fn ($c) => Schema::hasColumn('shipments', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
