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
        // `shipments.edit` lo tenian exactamente operations y admin (§D.3): el
        // `|| hasPermission()` no agregaba a nadie.
        return $user->hasAnyRole(['admin', 'operations']);
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->hasAnyRole(['admin', 'operations']);
    }

    public function assignDriver(User $user, Shipment $shipment): bool
    {
        // Llego de main con `|| hasPermission('dispatch.manage')`: ese metodo se
        // borro en el Lote 8 (§D.3, no hay permisos finos por el SSO) y ningun
        // rol lo tenia. Despachar es de la consola: operations o admin.
        return $user->hasAnyRole(['admin', 'operations']);
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
