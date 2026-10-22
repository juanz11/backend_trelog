<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifest_details', function (Blueprint $table) {
            if (! Schema::hasColumn('manifest_details', 'location')) {
                $table->string('location', 100)->nullable()->after('status');
            }
        });

        Schema::table('manifest_hub_scans', function (Blueprint $table) {
            if (! Schema::hasColumn('manifest_hub_scans', 'location')) {
                $table->string('location', 100)->nullable()->after('phase');
            }
            if (! Schema::hasColumn('manifest_hub_scans', 'condition')) {
                $table->string('condition', 30)->nullable()->after('location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manifest_details', function (Blueprint $table) {
            if (Schema::hasColumn('manifest_details', 'location')) {
                $table->dropColumn('location');
            }
        });
        Schema::table('manifest_hub_scans', function (Blueprint $table) {
            $cols = array_filter(['location', 'condition'], fn ($c) => Schema::hasColumn('manifest_hub_scans', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
