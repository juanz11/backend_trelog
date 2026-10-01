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
        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('package');
            $table->unsignedInteger('pieces')->default(1);
            $table->decimal('weight', 10, 2)->nullable();
            $table->string('weight_unit', 4)->default('kg');
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->decimal('volume_m3', 12, 6)->nullable();
            $table->decimal('declared_value', 12, 2)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_packages');
    }
};
