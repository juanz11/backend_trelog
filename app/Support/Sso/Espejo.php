<?php

namespace App\Support\Sso;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La fila espejo de una persona del SSO en `users`, asegurada desde codigo
 * (el comando `sso:espejo` hace lo mismo desde la consola). El ancla es
 * `sso_user_id`. Nunca roba el id de otra persona, nunca crea una segunda fila.
 *
 * DOS MODOS, y la diferencia es de seguridad:
 *
 *   vincularPorCorreo = false (gateway.user, primer ingreso): si no hay fila
 *     con esa ancla, se CREA; si existe una fila local con ese correo y sin
 *     ancla, NO se vincula (lanza). Un vinculo automatico por correo es la forma
 *     normal de robar una cuenta cuando el proveedor de identidad y el dominio
 *     no comparten el registro; ResolveDomainUserTest lo congela. Esos casos
 *     los resuelve un administrador con `sso:espejo`.
 *
 *   vincularPorCorreo = true (alta de conductor): hay un operador humano que
 *     eligio ese correo y el SSO lo devolvio verificado por Clerk. Se vincula la
 *     fila local, y al hacerlo se le ANULA la contraseña y se revocan sus
 *     tokens: desde ese momento a esa cuenta se entra solo por el SSO, asi que
 *     quien la hubiera creado por el camino viejo con ese correo se queda afuera.
 */
final class Espejo
{
    public static function asegurar(string $ssoUserId, string $email, ?string $nombre = null, bool $vincularPorCorreo = true): User
    {
        $dueno = User::where('sso_user_id', $ssoUserId)->first();
        if ($dueno && mb_strtolower($dueno->email) !== mb_strtolower($email)) {
            throw new RuntimeException("El sso_user_id {$ssoUserId} ya es de {$dueno->email} (users.id {$dueno->id}).");
        }

        $local = $dueno ? null : User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        if ($local && $local->sso_user_id !== null) {
            throw new RuntimeException("El correo {$email} ya es de otra identidad del SSO ({$local->sso_user_id}).");
        }
        if ($local && ! $vincularPorCorreo) {
            throw new RuntimeException("Ya existe una cuenta local con el correo {$email} y sin ancla: la vincula un administrador con sso:espejo.");
        }
        if ($local) {
            // Se vincula: la cuenta pasa a ser SOLO del SSO. Contraseña anulada y
            // tokens revocados, para que el camino viejo no siga entrando.
            $local->password = Hash::make(Str::random(48));
            $local->tokens()->delete();
        }

        $user = $dueno
            ?? $local
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
