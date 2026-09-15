<?php

namespace App\Support\Sso;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La fila espejo de una persona del SSO en `users`, asegurada desde codigo
 * (el comando `sso:espejo` hace lo mismo desde la consola). El ancla es
 * `sso_user_id`; el correo solo sirve para VINCULAR una fila local que todavia
 * no tiene ancla. Nunca roba el id de otra persona, nunca crea una segunda fila.
 */
final class Espejo
{
    public static function asegurar(string $ssoUserId, string $email, ?string $nombre = null): User
    {
        $dueno = User::where('sso_user_id', $ssoUserId)->first();
        if ($dueno && mb_strtolower($dueno->email) !== mb_strtolower($email)) {
            throw new RuntimeException("El sso_user_id {$ssoUserId} ya es de {$dueno->email} (users.id {$dueno->id}).");
        }

        $user = $dueno
            ?? User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->whereNull('sso_user_id')->first()
            // `password` sigue siendo NOT NULL hasta el Lote 9. La fila espejo lleva
            // una contraseña que nadie conoce ni puede adivinar: la persona entra por
            // el SSO, nunca por aca.
            ?? new User(['email' => $email, 'password' => Hash::make(Str::random(48))]);

        if ($nombre !== null && $nombre !== '') {
            $user->name = $nombre;
        }
        if ($user->name === null || $user->name === '') {
            $user->name = $email;
        }
        $user->forceFill(['sso_user_id' => $ssoUserId]);
        $user->save();

        return $user;
    }
}
