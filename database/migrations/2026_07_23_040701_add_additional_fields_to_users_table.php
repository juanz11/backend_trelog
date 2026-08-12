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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('status');
            $table->string('business_name')->nullable()->after('phone');
            $table->string('street_address')->nullable()->after('business_name');
            $table->string('city')->nullable()->after('street_address');
            $table->string('zone')->nullable()->after('city');
            $table->string('payment_method')->nullable()->after('zone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'business_name', 'street_address', 'city', 'zone', 'payment_method']);
        });
    }
};
