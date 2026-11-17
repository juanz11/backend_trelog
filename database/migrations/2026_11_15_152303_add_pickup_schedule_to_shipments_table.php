<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->date('pickup_date')->nullable()->after('delivery_mode');
            $table->string('pickup_window', 50)->nullable()->after('pickup_date');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['pickup_date', 'pickup_window']);
        });
    }
};
