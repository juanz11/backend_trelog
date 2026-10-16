<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DCU-08: entrada y salida de hub por paquete contra manifiesto (DRV §6.1)
        if (! Schema::hasTable('manifest_hub_scans')) {
            Schema::create('manifest_hub_scans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('manifest_id')->constrained('manifests')->cascadeOnDelete();
                $table->foreignId('manifest_detail_id')->nullable()->constrained('manifest_details')->nullOnDelete();
                $table->string('tracking_code');
                $table->string('phase'); // in|out
                $table->string('notes')->nullable();
                $table->string('evidence_path')->nullable(); // foto de evidencia del operario
                $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('scanned_at')->nullable();
                $table->timestamps();
            });
        }

        // Ajustes de inventario: el operario registra (solo anexado), el
        // supervisor de almacén aprueba para desbloquear el despacho.
        if (! Schema::hasTable('manifest_adjustments')) {
            Schema::create('manifest_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('manifest_id')->constrained('manifests')->cascadeOnDelete();
                $table->string('tracking_code');
                // FALTANTE|SOBRANTE|AVERIADO|OTRO
                $table->string('type');
                $table->string('reason');
                // PENDIENTE|APROBADO|RECHAZADO
                $table->string('status')->default('PENDIENTE')->index();
                $table->string('evidence_path')->nullable();
                $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('resolution_reason')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('manifests', function (Blueprint $table) {
            if (! Schema::hasColumn('manifests', 'hub_received_at')) {
                $table->timestamp('hub_received_at')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('manifests', 'hub_dispatched_at')) {
                $table->timestamp('hub_dispatched_at')->nullable()->after('hub_received_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropColumn(['hub_received_at', 'hub_dispatched_at']);
        });
        Schema::dropIfExists('manifest_adjustments');
        Schema::dropIfExists('manifest_hub_scans');
    }
};
