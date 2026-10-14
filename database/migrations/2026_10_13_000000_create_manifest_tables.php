<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manifests')) {
            Schema::create('manifests', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                // CREADO|ASIGNADO|EN_RUTA|EN_SOLICITUD_TRASPASO|COMPLETADO|
                // COMPLETADO_CON_INCIDENCIAS|TRANSFERIDO
                $table->string('status')->default('CREADO')->index();
                $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
                $table->foreignId('origin_manifest_id')->nullable()->constrained('manifests')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('assignment_rejection_reason')->nullable();
                $table->boolean('roadside_assistance_pending')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('handover_closed_at')->nullable();
                $table->boolean('handover_discrepancies')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('manifest_details')) {
            Schema::create('manifest_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('manifest_id')->constrained('manifests')->cascadeOnDelete();
                $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('tracking_code')->index();
                // PENDIENTE|ENTREGADO|NO_ENTREGADO|TRANSFERIDO
                $table->string('status')->default('PENDIENTE')->index();
                $table->foreignId('origin_detail_id')->nullable()->constrained('manifest_details')->nullOnDelete();
                $table->timestamp('visited_at')->nullable();
                $table->string('failure_reason')->nullable();
                $table->string('evidence_path')->nullable();
                $table->string('evidence_url')->nullable();
                $table->string('capture_via')->nullable(); // QR|MANUAL
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->float('gps_accuracy')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('manifest_transfer_requests')) {
            Schema::create('manifest_transfer_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('manifest_id')->constrained('manifests')->cascadeOnDelete();
                $table->string('reason');
                // PENDIENTE|APROBADA|RECHAZADA
                $table->string('status')->default('PENDIENTE')->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('resolution_reason')->nullable();
                $table->timestamp('resolved_at')->nullable();
                // IDs de manifest_details seleccionados para trasladar (soporta traspaso parcial)
                $table->json('detail_ids');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('manifest_handover_scans')) {
            Schema::create('manifest_handover_scans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('manifest_id')->constrained('manifests')->cascadeOnDelete(); // manifiesto derivado
                $table->string('tracking_code');
                $table->string('phase'); // out|in
                $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->timestamp('scanned_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_handover_scans');
        Schema::dropIfExists('manifest_transfer_requests');
        Schema::dropIfExists('manifest_details');
        Schema::dropIfExists('manifests');
    }
};
