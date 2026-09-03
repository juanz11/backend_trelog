<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('driver_profiles', 'driver_id')) {
            Schema::table('driver_profiles', function (Blueprint $table) {
                $table->string('driver_id')->nullable()->unique()->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_profiles', 'driver_id')) {
            Schema::table('driver_profiles', function (Blueprint $table) {
                $table->dropColumn('driver_id');
            });
        }
    }
};
