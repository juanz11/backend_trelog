<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quotes') || Schema::hasColumn('quotes', 'viewed_at')) {
            return;
        }
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
