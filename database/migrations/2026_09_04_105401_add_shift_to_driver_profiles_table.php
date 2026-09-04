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
        if (! Schema::hasColumn('driver_profiles', 'shift')) {
            Schema::table('driver_profiles', function (Blueprint $table) {
                $table->string('shift')->nullable()->after('hub');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('driver_profiles', 'shift')) {
            Schema::table('driver_profiles', function (Blueprint $table) {
                $table->dropColumn('shift');
            });
        }
    }
};
