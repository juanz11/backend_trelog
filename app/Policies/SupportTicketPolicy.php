<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('support.view');
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
        return $user->isAdmin() || $user->hasPermission('support.edit');
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
