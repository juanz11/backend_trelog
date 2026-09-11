<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Traduce la identidad del SSO a la fila de `users` que el dominio de TR3SLOG
 * ya usa como clave de sus nueve foreign keys.
 *
 * NO EXISTE EN MSH, y es la divergencia estructural del diseño (3-design.md
 * §C.2, divergencia 3): MSH no tiene tabla `users`, TR3SLOG si, con nueve FKs
 * colgando de `users.id`. Que la costura viva en SU PROPIA CLASE —en vez de
 * ensuciar AuthenticateFromGateway, que si es compartido— es lo que permite
 * seguir sincronizando aquel archivo con MSH sin merges a mano.
 *
 * Corre SIEMPRE despues de gateway.auth:
 *     Route::middleware(['gateway.auth', 'gateway.user'])
 *
 * Deja el usuario de dominio como usuario autenticado del request, para que las
 * 30+ llamadas a `$request->user()->id`, las cuatro Policies y los nueve
 * controladores sigan funcionando SIN TOCARSE. Esa es toda la razon de ser de
 * `auth()->setUser()`: es el unico punto donde una linea preserva el dominio
 * entero. La alternativa —un objeto propio inyectado a mano en cada
 * controlador— es el diff gigante que el diseño descarto en §A.
 */
class ResolveDomainUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('sso_user');

        if (! is_array($identity) || blank($identity['id'] ?? null)) {
            // Cadena mal armada: alguien monto gateway.user sin gateway.auth
            // delante. Es un error de programacion, no del cliente, y se dice
            // asi. Falla ruidosa a proposito: la alternativa —seguir de largo
            // sin usuario— dejaria una ruta "protegida" resolviendo anonima.
            return response()->json([
                'error'      => 'unauthenticated',
                'message'    => 'Falta gateway.auth antes de gateway.user en la cadena de middleware.',
                'request_id' => RequestContext::resolveFor($request),
            ], 401);
        }

        // EL ANCLA, Y LA UNICA. `sso_user_id` viene de X-User-Id (3-design.md §A).
        //
        // ACA NO HAY, NI VA A HABER, UN firstOrCreate POR EMAIL.
        // El email del SSO lo controla la persona desde su perfil de Clerk. Un
        // vinculo automatico por correo significa que cualquiera que registre en
        // el SSO el email de un cliente de TR3SLOG se queda con SU cuenta de
        // logistica: sus envios, sus direcciones, su facturacion. No es un riesgo
        // teorico ni un caso borde — es la forma normal de robar una cuenta
        // cuando el proveedor de identidad y el dominio no comparten el registro.
        // La spec lo prohibe explicitamente ("Migracion de cuentas existentes
        // fuera de alcance") y el test ResolveDomainUserTest lo congela.
        $user = User::where('sso_user_id', $identity['id'])->first();

        if ($user === null) {
            // La persona existe en el SSO pero no esta dada de alta en TR3SLOG.
            // 403 y mensaje explicito: el 401 diria "volve a loguearte", y
            // loguearse de nuevo no lo arregla nunca. Se distinguen las dos
            // cosas porque el cliente hace cosas distintas con cada una.
            //
            // Por que `forbidden` y no un slug nuevo tipo `account_not_provisioned`:
            // el catalogo de errores del contrato es CERRADO (trece slugs,
            // congelado con assertSame). Inventar uno es un cambio de contrato
            // unilateral que la app cliente no reconoce. Si hace falta el slug,
            // se pide; mientras tanto, `forbidden` con mensaje.
            Log::warning('gateway.user rechazo 403: identidad del SSO sin cuenta en TR3SLOG.', [
                'event'  => 'domain_user_not_provisioned',
                'reason' => 'sso_user_id_sin_fila',
                'method' => $request->getMethod(),
                'path'   => $request->path(),
                // El id del SSO NO es una credencial y es exactamente el dato que
                // hace falta para dar de alta la cuenta que falta. Va al log a
                // proposito; el email y el nombre, no.
                'sso_user_id' => $identity['id'],
            ]);

            return response()->json([
                'error'      => 'forbidden',
                'message'    => 'La cuenta existe en el SSO pero no esta dada de alta en TR3SLOG.',
                'request_id' => RequestContext::resolveFor($request),
            ], 403);
        }

        $this->refrescarEspejo($user, $identity);

        // Los roles del SSO viven en ESTA instancia y no en la base (Lote 8,
        // 3-design.md §D.4): `role_user` esta vacia para quien entra por aca, y
        // `hasAnyRole()`/`isAdmin()` en controladores y Policies leen de lo que se
        // hidrata en esta linea. Una instancia distinta de este mismo usuario
        // (`User::find()`, `->fresh()`) NO la tiene, y por eso lanza.
        $user->hidratarRolesSso(is_array($identity['roles'] ?? null) ? $identity['roles'] : []);

        // No emite eventos de login ni toca la sesion: no hay credenciales que
        // validar, el gateway ya decidio. Un guard de Laravel aca seria ceremonia
        // que ademas reintroduce el habito de Auth::attempt que estamos retirando.
        auth()->setUser($user);

        return $next($request);
    }

    /**
     * Refresca las columnas que gobierna el SSO.
     *
     * `name` y `email` sobreviven en `users` COMO CACHE DE LECTURA, no como
     * fuente de verdad ni como credencial. Se refrescan en cada peticion
     * justamente porque el riesgo real de dejar la tabla espejo es que alguien
     * lea `users.name` creyendo que es autoritativo.
     *
     * Solo se escribe lo que VINO. Una cabecera de valor vacio NO LLEGA (NGINX
     * omite el proxy_set_header), asi que una persona sin nombre en el SSO llega
     * sin X-User-Name: pisar el nombre local con "" seria interpretar "no me
     * mandaron el dato" como "el dato es vacio", que son cosas distintas.
     */
    private function refrescarEspejo(User $user, array $identity): void
    {
        $cambios = [];

        foreach ([
            'sso_clerk_id' => $identity['clerk_id'] ?? null,
            'email'        => $identity['email'] ?? null,
            'name'         => $identity['name'] ?? null,
        ] as $columna => $valor) {
            if (filled($valor) && $user->{$columna} !== $valor) {
                $cambios[$columna] = $valor;
            }
        }

        if ($cambios === []) {
            return;
        }

        // Asignacion atributo por atributo, NUNCA fill(): `sso_user_id` y
        // `sso_clerk_id` estan fuera de $fillable a proposito (ver la migracion),
        // y este es el unico lugar del sistema autorizado a escribirlos.
        foreach ($cambios as $columna => $valor) {
            $user->{$columna} = $valor;
        }

        try {
            $user->save();
        } catch (QueryException $e) {
            // `users.email` y `users.sso_clerk_id` son UNIQUE. Si el SSO manda un
            // email que otra fila local ya ocupa, el UPDATE explota.
            //
            // No se propaga: seria un 500 en CADA peticion de esa persona por no
            // poder refrescar un CACHE. La identidad de la peticion no depende de
            // esto —el ancla es sso_user_id, ya resuelta— asi que se sigue con el
            // valor viejo y se deja el conflicto en el log, que es donde alguien
            // lo puede arreglar. Fallar la peticion no arreglaria el choque, solo
            // dejaria al usuario afuera.
            $user->discardChanges();

            Log::warning('gateway.user no pudo refrescar el espejo de identidad.', [
                'event'       => 'domain_user_mirror_conflict',
                'sso_user_id' => $user->sso_user_id,
                'user_id'     => $user->id,
                // Que columnas, no que valores: los valores son datos personales.
                'columnas'    => array_keys($cambios),
                'db_error'    => $e->getCode(),
            ]);
        }
    }
}
