<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->decimal('per_km', 8, 2)->default(0)->after('dim_divisor');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            $table->dropColumn('per_km');
        });
    }
};
