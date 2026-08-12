<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('origin');
            $table->string('destination');
            $table->string('service_type')->nullable();
            $table->string('weight')->nullable();
            $table->string('dimensions')->nullable();
            $table->string('pieces')->nullable();
            $table->string('client_name');
            $table->string('client_email');
            $table->text('details')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
