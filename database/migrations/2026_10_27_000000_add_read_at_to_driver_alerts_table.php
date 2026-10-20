<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('driver_alerts', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('driver_alerts', function (Blueprint $table) {
            if (Schema::hasColumn('driver_alerts', 'read_at')) {
                $table->dropColumn('read_at');
            }
        });
    }
};
