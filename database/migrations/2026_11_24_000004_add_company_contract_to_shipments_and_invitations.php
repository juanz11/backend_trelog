<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_role')->default('agent'); // role in company_members
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('member_role');
        });
    }
};
