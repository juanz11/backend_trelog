<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same-day shipments now open a "solicitud" for the CLIENT (priority
     * handling in operations) instead of being driver-only requests, so the
     * request can exist without a driver.
     */
    public function up(): void
    {
        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->string('type')->default('driver')->after('id');
            $table->foreignId('driver_id')->nullable()->change();
            $table->foreignId('client_id')->nullable()->after('driver_id')->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('type');
            $table->foreignId('driver_id')->nullable(false)->change();
        });
    }
};
