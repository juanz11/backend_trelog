<?php

namespace App\Services\Sso;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TR3SLOG hablando con el SSO como APLICACION (client_credentials, scope
 * `app.users`): encontrar a una persona por correo y darle un rol `treslog:*`.
 * Contrato: SSO/Docs/contrato_api_apps_usuarios.md.
 *
 * Lo que este cliente no puede hacer, por diseño del SSO: crear a una persona
 * que no existe (eso es Clerk, y llega con las invitaciones), ni tocar roles
 * que no lleven el prefijo `treslog:`.
 */
class SsoAppClient
{
    private const CACHE_TOKEN = 'sso.backend_client.token.app.users';

    /** @return array{id: string, email: string, name: string, is_active: bool, roles: string[]}|null null si no existe */
    public function buscarPorCorreo(string $email): ?array
    {
        $r = $this->peticion()->get(config('sso.base_url').'/api/v1/apps/users', ['email' => $email]);
        if ($r->status() === 404) {
            return null;
        }

        return $this->datos($r);
    }

    /** @return array{id: string, email: string, name: string, is_active: bool, roles: string[]} */
    public function asignarRol(string $ssoUserId, string $rol): array
    {
        return $this->datos($this->peticion()->post(config('sso.base_url')."/api/v1/apps/users/{$ssoUserId}/roles", ['role' => $rol]));
    }

    private function datos(Response $r): array
    {
        if ($r->status() === 401) {
            Cache::forget(self::CACHE_TOKEN);
        }
        $r->throw();

        return $r->json('data');
    }

    private function peticion(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(10);
    }

    private function token(): string
    {
        return Cache::remember(self::CACHE_TOKEN, now()->addMinutes(50), function () {
            $id = config('sso.backend_client.id');
            $secreto = config('sso.backend_client.secret');
            if (! $id || ! $secreto) {
                throw new RuntimeException('Falta SSO_BACKEND_CLIENT_ID o SSO_BACKEND_CLIENT_SECRET: sin el cliente confidencial este backend no puede hablar con el SSO como aplicacion.');
            }

            return (string) Http::asForm()->acceptJson()->timeout(10)->post(config('sso.base_url').'/oauth/token', [
                'grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secreto, 'scope' => 'app.users',
            ])->throw()->json('access_token');
        });
    }
}
