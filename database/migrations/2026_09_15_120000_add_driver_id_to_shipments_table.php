<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shipments', 'driver_id')) {
            return;
        }
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('driver_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn('assigned_at');
        });
    }
};
