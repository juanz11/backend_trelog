<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table) {
            $table->boolean('fragile')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table) {
            $table->dropColumn('fragile');
        });
    }
};
