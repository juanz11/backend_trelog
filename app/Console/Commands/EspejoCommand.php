<?php

namespace App\Console\Commands;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Da de alta (o corrige) el ESPEJO local de una persona del SSO.
 *
 *     php artisan sso:espejo tu@correo.com 3
 *     php artisan sso:espejo tu@correo.com 3 --conductor
 *
 * POR QUE EXISTE: por el camino del SSO, TR3SLOG no crea usuarios. La persona
 * existe en el SSO y aca tiene que existir una fila en `users` con su
 * `sso_user_id`, o `gateway.user` responde 403 «la cuenta existe en el SSO pero
 * no esta dada de alta en TR3SLOG». Hasta ahora eso se hacia con un `tinker`
 * de cuatro lineas que nadie recuerda y que cada uno escribia distinto.
 *
 * EL ID ES DEL SSO CONTRA EL QUE TRABAJAS, y este es el error mas facil de
 * cometer: la misma persona tiene un id en el SSO local y OTRO en el del VPS.
 * Con el id cruzado, la web dice «tu cuenta no esta habilitada» y parece un
 * fallo del SSO cuando es un dato mal copiado. El id se ve en
 * `GET /api/v1/user` del SSO correspondiente, o en su panel.
 *
 * `sso_user_id` NO es fillable a proposito (un ancla de identidad asignable en
 * masa es un secuestro esperando un `User::create($request->all())`): por eso
 * va por `forceFill`, y este comando es uno de los dos lugares autorizados a
 * escribirlo; el otro es `ResolveDomainUser`, que solo lo refresca.
 */
class EspejoCommand extends Command
{
    protected $signature = 'sso:espejo
        {email : El correo de la persona, tal como esta en el SSO}
        {sso_user_id : Su id en el SSO contra el que trabajas (no en otro)}
        {--conductor : Le crea ademas el perfil de conductor, si no lo tiene}';

    protected $description = 'Crea o corrige la fila espejo local de una persona del SSO';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $ssoId = trim((string) $this->argument('sso_user_id'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $ssoId === '') {
            $this->error('Uso: sso:espejo <correo> <sso_user_id>');

            return self::INVALID;
        }

        // Si OTRA persona ya tiene ese sso_user_id, no se pisa en silencio: la
        // columna es unica, y el choque significa que alguien copio mal un id.
        $dueno = User::where('sso_user_id', $ssoId)->where('email', '!=', $email)->first();
        if ($dueno !== null) {
            $this->error("El sso_user_id {$ssoId} ya es de {$dueno->email} (users.id {$dueno->id}). Revisa el id antes de seguir.");

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => Str::before($email, '@')],
        );

        $cambio = $user->sso_user_id !== $ssoId;
        $user->forceFill(['sso_user_id' => $ssoId]);

        try {
            $user->save();
        } catch (QueryException $e) {
            $this->error('No se pudo guardar el espejo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %s -> users.id %d, sso_user_id %s%s',
            $user->wasRecentlyCreated ? 'CREADO' : 'ya existia',
            $email,
            $user->id,
            $ssoId,
            $cambio && ! $user->wasRecentlyCreated ? ' (corregido)' : '',
        ));

        if ($this->option('conductor')) {
            $perfil = DriverProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['initials' => strtoupper(substr($user->name, 0, 2)), 'vehicle' => 'Van 1', 'hub' => 'Santo Domingo', 'available' => true],
            );
            $this->info('   perfil de conductor: '.($perfil->wasRecentlyCreated ? 'creado' : 'ya existia'));
        }

        $this->line('   El rol lo decide el SSO (X-User-Roles), no esta fila: aca solo vive la identidad.');

        return self::SUCCESS;
    }
}
