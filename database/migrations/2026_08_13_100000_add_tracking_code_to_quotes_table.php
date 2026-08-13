<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quotes') || Schema::hasColumn('quotes', 'tracking_code')) {
            return;
        }
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('tracking_code')->unique()->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('tracking_code');
        });
    }
};
