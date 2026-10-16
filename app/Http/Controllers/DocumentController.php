<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function pending(): JsonResponse
    {
        $documents = Document::where('status', Document::STATUS_PENDING)
            ->with(['documentable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    DriverProfile::class => ['user'],
                    Vehicle::class => ['driverProfile.user'],
                ]);
            }])
            ->oldest()
            ->get();

        return response()->json($documents->map(fn (Document $doc) => $this->toApi($doc))->values());
    }

    public function review(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ]);

        $document->update([
            'status' => $validated['status'],
            'rejection_reason' => $validated['status'] === 'rejected' ? $validated['reason'] : null,
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
        ]);

        $profile = $this->ownerProfile($document);
        if ($profile) {
            $profile->verification_status = $this->verificationStatus($profile);
            $profile->save();
        }

        $document->load(['documentable' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                DriverProfile::class => ['user'],
                Vehicle::class => ['driverProfile.user'],
            ]);
        }]);

        return response()->json($this->toApi($document));
    }

    private function ownerProfile(Document $document): ?DriverProfile
    {
        $owner = $document->documentable;

        return match (true) {
            $owner instanceof DriverProfile => $owner,
            $owner instanceof Vehicle => $owner->driverProfile,
            default => null,
        };
    }

    private function verificationStatus(DriverProfile $profile): string
    {
        $docs = Document::where(function ($q) use ($profile) {
            $q->where('documentable_type', (new DriverProfile)->getMorphClass())
                ->where('documentable_id', $profile->id);
        })->orWhere(function ($q) use ($profile) {
            $q->where('documentable_type', (new Vehicle)->getMorphClass())
                ->whereIn('documentable_id', $profile->vehicles()->pluck('id'));
        })->get();

        if ($docs->contains('status', Document::STATUS_REJECTED)) {
            return 'rejected';
        }
        if ($docs->isNotEmpty() && $docs->every(fn ($d) => $d->status === Document::STATUS_APPROVED)) {
            return 'verified';
        }

        return 'pending';
    }

    private function toApi(Document $doc): array
    {
        $owner = $doc->documentable;
        $profile = $owner instanceof DriverProfile ? $owner : $owner?->driverProfile;

        return [
            'id' => $doc->id,
            'type' => $doc->type,
            'subject' => $owner instanceof Vehicle ? 'vehicle' : 'driver',
            'vehicle' => $owner instanceof Vehicle ? trim(implode(' ', array_filter([$owner->label, $owner->plate]))) : null,
            'number' => $doc->number,
            'expires_at' => $doc->expires_at?->toDateString(),
            'file_path' => $doc->file_path,
            'status' => $doc->status,
            'rejection_reason' => $doc->rejection_reason,
            'validated_at' => $doc->validated_at?->toIso8601String(),
            'created_at' => $doc->created_at?->toIso8601String(),
            'driver_id' => $profile?->driver_id,
            'driver_name' => $profile?->user?->name,
            'driver_user_id' => $profile?->user_id,
        ];
    }
}
