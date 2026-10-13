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
        // D7, resuelto con el equipo (2026-09-14, 8-respuestas-del-equipo.md):
        // cotiza EL CLIENTE, anonimo por correo o registrado, por el camino
        // publico. Este metodo no gobierna ninguna ruta: `POST /quotes` de la
        // consola lo protege el middleware de operaciones y es la recepcion
        // manual de una cotizacion (nombre y correo del cliente), que se mantiene
        // tal cual. Se conserva la traduccion mas restrictiva de N1 por si algun
        // dia alguien lo conecta: no concede nada que hoy no exista (§D.5).
        return $user->isAdmin();
    }

    public function update(User $user, Quote $quote): bool
    {
        // D7: `quotes.edit` no lo tenia nadie (N1) y el equipo no pidio otra cosa;
        // cambiar el estado de una cotizacion queda en admin. Ver create().
        return $user->isAdmin();
    }

    public function delete(User $user, Quote $quote): bool
    {
        // D7: idem update(). Nadie borra cotizaciones salvo admin.
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
