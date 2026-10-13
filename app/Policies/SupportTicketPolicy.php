<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        // @todo D7 — `support.view` no lo tenia NADIE (N1): el `||` era letra
        // muerta. Queda admin, que es lo que hoy pasa (§D.5).
        return $user->isAdmin();
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->isAdmin() || $user->id === $ticket->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        // @todo D7 — `support.edit`, idem viewAny().
        return $user->isAdmin();
    }

    public function delete(User $user, SupportTicket $ticket): bool
    {
        return false;
    }

    public function restore(User $user, SupportTicket $ticket): bool
    {
        return false;
    }

    public function forceDelete(User $user, SupportTicket $ticket): bool
    {
        return false;
    }
}
