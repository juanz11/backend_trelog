<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Sin `password`, `remember_token` ni `reset_token*` (Lote 9): esta tabla no
// autentica a nadie. `sso_user_id` y `sso_roles` NO son fillable a proposito:
// los escribe solo el espejo (Espejo::asegurar / ResolveDomainUser).
#[Fillable(['name', 'company', 'phone', 'email', 'status', 'business_name', 'street_address', 'city', 'zone', 'payment_method'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'sso_roles' => 'array',
        ];
    }

    // `roles()` (belongsToMany Role) SE BORRO en el Lote 9 junto con las tablas
    // `roles`/`role_user`. Los roles de una persona los dice el SSO en cada
    // peticion; lo que queda en la fila es `sso_roles`, una FOTO de los ultimos
    // que emitio, y sirve para LISTAR (conductores, clientes), nunca para
    // autorizar. Autorizar es `hasRole()`/`hasAnyRole()` sobre la instancia
    // hidratada.

    /**
     * Los roles que el SSO emitio para ESTA peticion (X-User-Roles), tal como los
     * hidrato ResolveDomainUser. `null` significa "esta instancia no fue
     * hidratada", que NO es lo mismo que "no tiene roles" (eso seria `[]`).
     *
     * Es una propiedad de la instancia y no de la fila a proposito (3-design.md
     * §D.4): por el camino del SSO los roles son un atributo de la PETICION, no
     * del usuario en la base. `role_user` esta vacia para quien entra por el SSO.
     *
     * @var list<string>|null
     */
    private ?array $rolesSso = null;

    /**
     * La llama ResolveDomainUser, y solo ella, con los roles de X-User-Roles.
     *
     * @param  list<string>  $roles
     */
    public function hidratarRolesSso(array $roles): static
    {
        $this->rolesSso = array_values(array_map('strval', $roles));

        return $this;
    }

    public function tieneRolesSsoHidratados(): bool
    {
        return $this->rolesSso !== null;
    }

    /**
     * De donde salen los roles de esta instancia. DOS comportamientos desde el
     * Lote 9 (antes habia un tercero, el camino viejo con `role_user`, que
     * murio con la tabla):
     *
     *   (a) hidratada por ResolveDomainUser -> los roles del SSO.
     *   (b) NO hidratada -> LANZA. Es un `User::find()` (o un `->fresh()`) en un
     *       controlador, o un `hasRole()` desde un job o `tinker`: la fila no
     *       sabe nada de roles, y devolver `false` seria una denegacion fantasma
     *       sin rastro en ningun log. Si algo dejo de ser posible, tiene que
     *       dejar de compilar (§D.3); y si no puede dejar de compilar, tiene que
     *       explotar. Para LISTAR por rol (no autorizar) esta `sso_roles`.
     *
     * @return list<string>
     */
    private function rolesDeLaPeticion(): array
    {
        if ($this->rolesSso !== null) {
            return $this->rolesSso;
        }

        throw new \LogicException(sprintf(
            'User #%s no fue hidratado con la identidad del SSO. Los roles son de la PETICION, no de la '.
            'fila: usa $request->user(), no User::find() ni ->fresh(). Para listar personas por rol esta la '.
            'foto `sso_roles`; para los roles de OTRA persona, esa pregunta se le hace al SSO.',
            (string) $this->getKey(),
        ));
    }

    /**
     * `admin` -> `treslog:admin` via config('sso.roles'). Un rol que ya viene
     * completo (o que no esta en la tabla) se devuelve tal cual, para que un
     * `hasRole('treslog:admin')` tambien funcione.
     */
    private function rolCompleto(string $rol): string
    {
        return (string) config('sso.roles.'.$rol, $rol);
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole($roleName): bool
    {
        return in_array($this->rolCompleto((string) $roleName), $this->rolesDeLaPeticion(), true);
    }

    /**
     * Check if user has any of the given roles (OR).
     */
    public function hasAnyRole($roleNames): bool
    {
        $sso = $this->rolesDeLaPeticion();

        foreach ((array) $roleNames as $rol) {
            if (in_array($this->rolCompleto((string) $rol), $sso, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    // `hasPermission()` SE BORRO en el Lote 8 (3-design.md §D.3). No devuelve
    // `false` ni `true`: no existe. El backend no puede resolver permisos finos
    // por el camino del SSO (H1), y un metodo que "parece" resolverlos es la
    // trampa: la proxima persona agrega un permiso, no funciona, y no hay nada
    // que leer que se lo explique. Cada call site se tradujo a una guarda de rol
    // segun la matriz de §D.3; los que nadie tenia (N1) quedaron en `isAdmin()`
    // con `@todo D7`.

    /**
     * Driver profile relationship.
     */
    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    /**
     * Driver alerts relationship.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(DriverAlert::class, 'driver_id');
    }

    /**
     * User addresses.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
