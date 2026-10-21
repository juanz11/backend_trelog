<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('delivery_mode');
            }
            if (! Schema::hasColumn('shipments', 'payment_method_id')) {
                $table->string('payment_method_id')->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('shipments', 'payment_bank')) {
                $table->string('payment_bank')->nullable()->after('payment_method_id');
            }
            if (! Schema::hasColumn('shipments', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('payment_bank');
            }
            if (! Schema::hasColumn('shipments', 'charge_to_account')) {
                $table->string('charge_to_account')->nullable()->after('payment_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $cols = ['payment_method', 'payment_method_id', 'payment_bank', 'payment_reference', 'charge_to_account'];
            $existing = array_filter($cols, fn ($c) => Schema::hasColumn('shipments', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
