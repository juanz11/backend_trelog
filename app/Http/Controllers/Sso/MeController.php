<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Quien soy» por el camino del SSO: la identidad de la peticion cruzada con la
 * fila espejo del dominio.
 *
 * POR QUE EXISTE, y por que no alcanza con `GET /api/v1/user` del SSO:
 * el SSO devuelve trece claves y NINGUNA es `roles` (contrato §4.1, "No vienen
 * `roles`"). El cliente los pediria por `GET /api/v1/authorization`, que es otra
 * peticion, otro sobre y otro momento — y encima devuelve los permisos de la
 * aplicacion, no el `user_id` local del que cuelgan las nueve foreign keys del
 * dominio. La web necesita las tres cosas juntas para pintar una pantalla:
 * quien es, que puede ver, y con que id se le piden sus envios.
 *
 * DE DONDE SALEN LOS ROLES: de la identidad que dejo `AuthenticateFromGateway`
 * en el request, o sea de `X-User-Roles`, o sea del SSO. NUNCA de `role_user`.
 * Para quien entra por el SSO esa tabla esta VACIA —su fila espejo se crea con
 * `sso_user_id` y nada mas— asi que `$user->roles` devolveria una lista vacia y
 * un conductor real veria la web de un cliente sin un solo error en el log
 * (hallazgo 2 de la auditoria, el mismo que hace inservible el middleware
 * `driver` por el camino nuevo).
 *
 * POR QUE NO LLEVA `sso.role`: preguntar quien soy no exige ser nada. Cualquier
 * identidad con fila espejo puede hacerlo; el que no la tiene se lo dice
 * `gateway.user` con un 403 (ver el comentario de la ruta en routes/api.php).
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $roles = $this->rolesDeEstaAplicacion($request);

        return response()->json([
            'data' => [
                // El id LOCAL, que es el que el dominio entiende: las nueve FKs
                // (`shipments.user_id`, `routes.driver_id`, ...) apuntan aca, no
                // al id del SSO. La web lo usa para `PUT /users/{id}`.
                'id'          => $user->id,
                // El ancla, publicada a proposito: es el dato que hace falta para
                // dar de alta a alguien que todavia no esta, y no es una
                // credencial (el gateway lo pone en una cabecera en claro).
                'sso_user_id' => $user->sso_user_id,

                // Identidad: gobernada por el SSO. `users.name`/`users.email`
                // son CACHE de lectura que `ResolveDomainUser` acaba de
                // refrescar con lo que vino en las cabeceras, asi que leerlas de
                // la fila y no del header da lo mismo y ademas funciona cuando
                // el SSO no mando el dato (NGINX omite la cabecera vacia).
                'name'        => $user->name,
                'email'       => $user->email,

                // Dominio: vive en TR3SLOG y el SSO no sabe que existe. Es la
                // mitad del perfil que sigue guardandose por el backend.
                'company'     => $user->company,
                'phone'       => $user->phone,
                'status'      => $user->status,

                'roles'       => $roles,
                'is_admin'    => $this->alcanzaLaConsola($roles),
            ],
        ]);
    }

    /**
     * Los roles de `X-User-Roles`, filtrados a los de ESTA aplicacion.
     *
     * El filtro es defensa en profundidad, no cosmetica: el gateway ya pide al
     * SSO los roles con alcance `treslog` y el SSO filtra por aplicacion
     * (`ApplicationScope::alcanza`). Si ese filtro fallara —o si alguien
     * alcanzara este backend sin pasar por el gateway, que hoy es posible y esta
     * documentado en SuperficieAbiertaSelloForjableTest— un `msh:user` o un
     * `tienda:vendedor` llegarian hasta aca y la web los pintaria como si fueran
     * roles suyos. Un rol de otro inquilino no significa NADA en TR3SLOG.
     *
     * El prefijo sale de `config('sso.slug')`, no de un literal: es el mismo
     * slug con el que el SSO emite los roles y el que arma las guardas de
     * `config('sso.roles')`.
     *
     * @return list<string>
     */
    private function rolesDeEstaAplicacion(Request $request): array
    {
        $identity = $request->attributes->get('sso_user');
        $prefijo  = config('sso.slug').':';

        return array_values(array_filter(
            is_array($identity) ? ($identity['roles'] ?? []) : [],
            static fn (string $rol): bool => str_starts_with($rol, $prefijo),
        ));
    }

    /**
     * Si esta persona ve la consola de administracion de la web.
     *
     * SE LLAMA `is_admin` PERO SIGNIFICA «alcanza la consola», y el nombre no es
     * un descuido: es exactamente la pregunta que la web ya se hacia en
     * AppShell.jsx (`['admin','operations'].includes(r.name)`) para elegir entre
     * el menu administrativo y el del cliente. Se responde ACA, en un solo
     * lugar, porque la version de la web leia objetos `{name}` de `role_user`
     * —la tabla que para el SSO esta vacia— y el sintoma de esa rotura no es un
     * error: es un admin viendo el portal del cliente.
     *
     * NO es una guarda de autorizacion y no reemplaza a ninguna: lo que se puede
     * hacer lo decide `sso.role` en la ruta. Esto solo decide que se DIBUJA.
     *
     * @param  list<string>  $roles
     */
    private function alcanzaLaConsola(array $roles): bool
    {
        return (bool) array_intersect($roles, [
            config('sso.roles.admin'),
            config('sso.roles.operations'),
        ]);
    }
}
