<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            foreach ([
                'sender_name', 'sender_company', 'sender_address', 'sender_city',
                'sender_country', 'sender_zip', 'sender_phone', 'sender_email',
                'recipient_company', 'recipient_address', 'recipient_city',
                'recipient_country', 'recipient_zip',
            ] as $col) {
                if (! Schema::hasColumn('shipments', $col)) {
                    $table->string($col)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $cols = array_filter([
                'sender_name', 'sender_company', 'sender_address', 'sender_city',
                'sender_country', 'sender_zip', 'sender_phone', 'sender_email',
                'recipient_company', 'recipient_address', 'recipient_city',
                'recipient_country', 'recipient_zip',
            ], fn ($c) => Schema::hasColumn('shipments', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
