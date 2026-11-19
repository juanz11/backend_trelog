<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('return_reviewed_at')->nullable()->after('cancel_fee_percent');
            $table->foreignId('return_reviewed_by')->nullable()->after('return_reviewed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_reviewed_by');
            $table->dropColumn('return_reviewed_at');
        });
    }
};
