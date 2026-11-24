<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->can('shipments.view')) {
            return true;
        }
        if ($shipment->user_id === $user->id) {
            return true;
        }
        // Miembros de la misma empresa por contrato ven los envíos de la empresa.
        $company = $user->company();
        return $company && $shipment->company_id === $company->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.edit');
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.delete');
    }

    public function assignDriver(User $user, Shipment $shipment): bool
    {
        return $user->can('dispatch.manage');
    }

    public function restore(User $user, Shipment $shipment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Shipment $shipment): bool
    {
        return false;
    }
}
