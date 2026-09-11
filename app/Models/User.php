<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'company', 'phone', 'email', 'password', 'status', 'business_name', 'street_address', 'city', 'zone', 'payment_method', 'reset_token', 'reset_token_expires'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The roles that belong to the user.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

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
     * De donde salen los roles de esta instancia. TRES comportamientos (Lote 8,
     * D8.4), y los tres estan probados en RolesSinHidratarLanzanTest:
     *
     *   (a) hidratada por ResolveDomainUser -> los roles del SSO.
     *   (b) NO hidratada, pero la peticion entro por el gateway -> LANZA. Es un
     *       `User::find()` (o un `->fresh()`) en un controlador del camino nuevo:
     *       la fila no sabe nada de roles, y devolver `false` seria una
     *       denegacion fantasma sin rastro en ningun log. Si algo dejo de ser
     *       posible, tiene que dejar de compilar (§D.3); y si no puede dejar de
     *       compilar, tiene que explotar.
     *   (c) NO hidratada y sin identidad de gateway en la peticion (el camino
     *       viejo con Sanctum, un job en cola, `tinker`) -> `null`, y el que
     *       llama lee `role_user` como siempre. El camino viejo no cambia ni un
     *       bit hasta el Lote 9.
     *
     * @return list<string>|null
     */
    private function rolesDeLaPeticion(): ?array
    {
        if ($this->rolesSso !== null) {
            return $this->rolesSso;
        }

        $request = app()->bound('request') ? app('request') : null;

        if ($request !== null && $request->attributes->has('sso_user')) {
            throw new \LogicException(sprintf(
                'User #%s no fue hidratado con la identidad del SSO. En una peticion que entro por el '.
                'gateway los roles son de la PETICION, no de la fila: usa $request->user(), no User::find() '.
                'ni ->fresh(). Si de verdad necesitas los roles de OTRA persona, esa pregunta se le hace al SSO.',
                (string) $this->getKey(),
            ));
        }

        return null;
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
        $sso = $this->rolesDeLaPeticion();

        if ($sso === null) {
            return $this->roles()->where('name', $roleName)->exists();
        }

        return in_array($this->rolCompleto((string) $roleName), $sso, true);
    }

    /**
     * Check if user has any of the given roles (OR).
     */
    public function hasAnyRole($roleNames): bool
    {
        $sso = $this->rolesDeLaPeticion();

        if ($sso === null) {
            return $this->roles()->whereIn('name', (array) $roleNames)->exists();
        }

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
