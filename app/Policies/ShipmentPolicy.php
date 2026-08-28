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
        return $user->hasAnyRole(['admin', 'operations']) || $shipment->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->hasPermission('shipments.edit');
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->hasPermission('shipments.delete');
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
