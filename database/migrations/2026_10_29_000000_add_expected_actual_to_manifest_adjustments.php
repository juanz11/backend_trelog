<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifest_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('manifest_adjustments', 'expected_value')) {
                $table->string('expected_value')->nullable()->after('reason');
            }
            if (! Schema::hasColumn('manifest_adjustments', 'actual_value')) {
                $table->string('actual_value')->nullable()->after('expected_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manifest_adjustments', function (Blueprint $table) {
            $cols = array_filter(['expected_value', 'actual_value'], fn ($c) => Schema::hasColumn('manifest_adjustments', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
