<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('prohibited_declared_at')->nullable();
            $table->string('prohibited_list_version', 32)->nullable();
            $table->string('prohibited_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['prohibited_declared_at', 'prohibited_list_version', 'prohibited_ip']);
        });
    }
};
