<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La costura de identidad con el SSO (3-design.md §A).
 *
 * `users` NO se muere: sobrevive como tabla espejo. Las nueve FKs de dominio
 * (shipments, routes, incidents, payroll_periods, driver_profiles, ...) siguen
 * apuntando a `users.id` bigint, o sea que la integridad referencial que hoy
 * garantiza el motor se queda donde esta. Lo unico que cambia es COMO se llega
 * a esa fila: antes por sesion de Sanctum, ahora por `sso_user_id = X-User-Id`.
 *
 * POR QUE EL ANCLA ES `sso_user_id` Y NO `sso_clerk_id`:
 * `X-User-Id` es el unico identificador que el SSO devuelve por mas de un canal
 * (cabecera del gateway, `GET /api/v1/user` y `GET /api/v1/profile`). `clerk_id`
 * esta EXPLICITAMENTE excluido de las respuestas de la API del SSO, con un test
 * que falla si aparece. Anclar ahi dejaria a TR3SLOG sin ningun endpoint capaz
 * de resolver una de sus propias filas: ni para soporte, ni para conciliar.
 *
 * `sso_clerk_id` se guarda igual, sin ser autoritativo, para que equivocarse en
 * esa decision cueste un `UPDATE ... SET sso_user_id = sso_clerk_id` y no un
 * proyecto de reconciliacion.
 *
 * NULLABLE A PROPOSITO: durante la convivencia hay filas que todavia entran por
 * `auth:sanctum` y no tienen identidad en el SSO. Un NOT NULL aca obligaria a
 * inventar un valor, y ese valor inventado seria indistinguible de uno real.
 *
 * UNIQUE A PROPOSITO: es la ultima linea de defensa contra la duplicacion de
 * identidad. Si dos filas de `users` reclamaran el mismo `sso_user_id`, la
 * pregunta "de quien es este envio" deja de tener respuesta.
 *
 * NINGUNA DE LAS DOS VA EN `$fillable`. Un ancla de identidad asignable en masa
 * es un secuestro de cuenta esperando un `User::create($request->all())`: quien
 * controle el cuerpo de una peticion elige de quien es la fila. Se escriben solo
 * desde `ResolveDomainUser`, atributo por atributo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_user_id', 64)->nullable()->unique()->after('id');
            $table->string('sso_clerk_id', 64)->nullable()->unique()->after('sso_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Los indices se sueltan antes que las columnas: SQLite no borra una
            // columna que todavia participa de un indice.
            $table->dropUnique(['sso_user_id']);
            $table->dropUnique(['sso_clerk_id']);
            $table->dropColumn(['sso_user_id', 'sso_clerk_id']);
        });
    }
};
