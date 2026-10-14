<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Manifest extends Model
{
    public const STATUS_CREADO = 'CREADO';
    public const STATUS_ASIGNADO = 'ASIGNADO';
    public const STATUS_EN_RUTA = 'EN_RUTA';
    public const STATUS_EN_SOLICITUD_TRASPASO = 'EN_SOLICITUD_TRASPASO';
    public const STATUS_COMPLETADO = 'COMPLETADO';
    public const STATUS_COMPLETADO_CON_INCIDENCIAS = 'COMPLETADO_CON_INCIDENCIAS';
    public const STATUS_TRANSFERIDO = 'TRANSFERIDO';

    public const STATUSES = [
        self::STATUS_CREADO,
        self::STATUS_ASIGNADO,
        self::STATUS_EN_RUTA,
        self::STATUS_EN_SOLICITUD_TRASPASO,
        self::STATUS_COMPLETADO,
        self::STATUS_COMPLETADO_CON_INCIDENCIAS,
        self::STATUS_TRANSFERIDO,
    ];

    protected $fillable = [
        'code', 'status', 'driver_id', 'vehicle_id', 'origin_manifest_id',
        'created_by', 'assignment_rejection_reason', 'roadside_assistance_pending',
        'started_at', 'closed_at', 'handover_closed_at', 'handover_discrepancies',
    ];

    protected function casts(): array
    {
        return [
            'roadside_assistance_pending' => 'boolean',
            'handover_discrepancies' => 'boolean',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'handover_closed_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Manifest::class, 'origin_manifest_id');
    }

    public function derivedManifests(): HasMany
    {
        return $this->hasMany(Manifest::class, 'origin_manifest_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ManifestDetail::class)->orderBy('sequence');
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(ManifestTransferRequest::class)->latest();
    }

    public function handoverScans(): HasMany
    {
        return $this->hasMany(ManifestHandoverScan::class)->orderBy('scanned_at');
    }

    public function progress(): float
    {
        $total = $this->details->count();
        if ($total === 0) {
            return 0.0;
        }
        $resolved = $this->details->whereIn('status', [
            ManifestDetail::STATUS_ENTREGADO,
            ManifestDetail::STATUS_NO_ENTREGADO,
        ])->count();

        return $resolved / $total;
    }

    /** Serialización alineada con el contrato del frontend/mock API. */
    public function toApi(): array
    {
        $this->loadMissing(['details', 'transferRequests']);

        return [
            'id' => $this->id,
            'codigo' => $this->code,
            'estado' => $this->status,
            'choferId' => $this->driver_id,
            'vehiculoId' => $this->vehicle_id,
            'idManifiestoOrigen' => $this->origin_manifest_id,
            'creadoPor' => $this->created_by,
            'fechaCreacion' => $this->created_at?->toISOString(),
            'fechaInicioRuta' => $this->started_at?->toISOString(),
            'fechaCierre' => $this->closed_at?->toISOString(),
            'asistenciaVialPendiente' => $this->roadside_assistance_pending,
            'progreso' => $this->progress(),
            'detalles' => $this->details->map(fn (ManifestDetail $d) => [
                'id' => $d->id,
                'secuenciaVisita' => $d->sequence,
                'pedidoId' => $d->shipment_id,
                'codigoSeguimiento' => $d->tracking_code,
                'detalleOrigenId' => $d->origin_detail_id,
                'estado' => $d->status,
                'horaVisita' => $d->visited_at?->toISOString(),
                'motivoFallo' => $d->failure_reason,
                'evidenciaUrl' => $d->evidence_url ?: ($d->evidence_path ? Storage::url($d->evidence_path) : null),
                'capturaVia' => $d->capture_via,
                'lat' => $d->lat,
                'lng' => $d->lng,
                'gpsPrecision' => $d->gps_accuracy,
            ])->values(),
            'solicitudes' => $this->transferRequests->map(fn (ManifestTransferRequest $s) => [
                'id' => $s->id,
                'fechaSolicitud' => $s->created_at?->toISOString(),
                'motivoIncidencia' => $s->reason,
                'estado' => $s->status,
                'solicitadoPor' => $s->requested_by,
                'revisadoPor' => $s->reviewed_by,
                'fechaResolucion' => $s->resolved_at?->toISOString(),
                'motivoResolucion' => $s->resolution_reason,
                'items' => $s->detail_ids,
            ])->values(),
            'derivados' => $this->derivedManifests->map(fn (Manifest $d) => [
                'id' => $d->id,
                'codigo' => $d->code,
                'estado' => $d->status,
            ])->values(),
        ];
    }
}
