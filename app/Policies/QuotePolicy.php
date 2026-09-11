<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        // `quotes.view` lo tenian operations y admin (3-design.md §D.3).
        return $user->hasAnyRole(['operations', 'admin']);
    }

    public function view(User $user, Quote $quote): bool
    {
        // `quotes.view` lo tenian operations y admin (3-design.md §D.3).
        return $user->hasAnyRole(['operations', 'admin']);
    }

    public function create(User $user): bool
    {
        // @todo D7 — `quotes.create` no lo tenia NADIE (N1). Traduccion mas
        // restrictiva posible: no concede nada que hoy no exista (§D.5).
        return $user->isAdmin();
    }

    public function update(User $user, Quote $quote): bool
    {
        // @todo D7 — `quotes.edit` no lo tenia nadie (N1). Ver create().
        return $user->isAdmin();
    }

    public function delete(User $user, Quote $quote): bool
    {
        // @todo D7 — `quotes.delete` no lo tenia nadie (N1). Ver create().
        return $user->isAdmin();
    }

    public function restore(User $user, Quote $quote): bool
    {
        return false;
    }

    public function forceDelete(User $user, Quote $quote): bool
    {
        return false;
    }
}
