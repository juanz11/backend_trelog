<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('close_received_by')->nullable();
            $table->unsignedInteger('close_quantity')->nullable();
            $table->string('close_condition')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn([
                'close_received_by',
                'close_quantity',
                'close_condition',
            ]);
        });
    }
};
