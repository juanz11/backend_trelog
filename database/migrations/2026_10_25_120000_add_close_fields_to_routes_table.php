<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->text('close_notes')->nullable();
            $table->string('close_photo_path')->nullable();
            $table->string('close_signature_path')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn([
                'close_notes',
                'close_photo_path',
                'close_signature_path',
                'completed_at',
            ]);
        });
    }
};
