<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('return_processed_at')->nullable()->after('return_reviewed_by');
            $table->foreignId('return_processed_by')->nullable()->after('return_processed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_processed_by');
            $table->dropColumn('return_processed_at');
        });
    }
};
