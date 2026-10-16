<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->string('received_by')->nullable()->after('state');
            $table->unsignedInteger('quantity')->nullable()->after('received_by');
            $table->string('condition', 50)->nullable()->after('quantity');
            $table->text('notes')->nullable()->after('condition');
            $table->string('photo_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn([
                'received_by',
                'quantity',
                'condition',
                'notes',
                'photo_path',
            ]);
        });
    }
};
