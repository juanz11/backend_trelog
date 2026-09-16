<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lote 9 de integracion-sso: TR3SLOG deja de autenticar.
 *
 * Se van las tablas del RBAC local (`roles`, `permissions`, `role_permission`,
 * `role_user`), los tokens de Sanctum (`personal_access_tokens`), los tokens de
 * reseteo (`password_reset_tokens`) y el flujo viejo de invitaciones por correo
 * con contraseña (`user_invitations`; el nuevo vive en el SSO). De `users` se
 * van `password`, `remember_token`, `reset_token` y `reset_token_expires`.
 *
 * Entra `users.sso_roles`: la FOTO de los ultimos roles que el SSO emitio para
 * esa persona, escrita por ResolveDomainUser en cada peticion. Sirve para
 * LISTAR (clientes, conductores); NUNCA para autorizar — para eso estan los
 * roles hidratados en la instancia (User::hasRole).
 *
 * NO TIENE VUELTA ATRAS POR MIGRACION. `down()` lanza a proposito: recrear las
 * tablas vacias no devuelve ni una contraseña ni un rol. La vuelta atras es el
 * respaldo que `treslog_en_vps.sh` saca ANTES de migrar (tarea 9.3) y el codigo
 * anterior. D3 y D6 (8-respuestas-del-equipo.md): no hay cuentas ni
 * instalaciones que dependan de lo que se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'sso_roles')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('sso_roles')->nullable()->after('sso_user_id');
            });
        }

        // Primero las que cuelgan de otras (FK), despues las padre.
        foreach (['role_user', 'role_permission', 'permissions', 'roles', 'personal_access_tokens', 'password_reset_tokens', 'user_invitations'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        $columnas = array_values(array_filter(
            ['password', 'remember_token', 'reset_token', 'reset_token_expires'],
            fn (string $c) => Schema::hasColumn('users', $c),
        ));

        if ($columnas !== []) {
            Schema::table('users', function (Blueprint $table) use ($columnas) {
                $table->dropColumn($columnas);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'El Lote 9 no se deshace por migracion: restaura el respaldo previo (treslog_en_vps.sh lo deja en /root/respaldos) '.
            'y despliega el codigo anterior.'
        );
    }
};
