<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('route_stops', 'shipment_id')) {
            return;
        }
        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('route_id')
                ->constrained('shipments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
        });
    }
};
