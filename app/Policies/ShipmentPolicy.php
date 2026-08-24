<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shipments.view') || $user->hasPermission('shipments.view_own');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->hasPermission('shipments.view')) {
            return true;
        }

        return $user->hasPermission('shipments.view_own') && $shipment->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shipments.create');
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
