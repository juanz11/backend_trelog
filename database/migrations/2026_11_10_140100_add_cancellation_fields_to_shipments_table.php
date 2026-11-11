<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('invoiced_at');
            $table->decimal('cancel_fee', 10, 2)->nullable()->after('cancelled_at');
            $table->decimal('cancel_fee_percent', 5, 2)->nullable()->after('cancel_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancel_fee', 'cancel_fee_percent']);
        });
    }
};
