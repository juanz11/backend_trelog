<?php

namespace Tests\Feature\Sso;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RutaRegistrada;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Lote 9 de integracion-sso: TR3SLOG ya no autentica, y que no vuelva.
 *
 * Hasta el Lote 9 este archivo era CierreDeAgujerosTest: probaba que los tres
 * agujeros de 1-proposal.md §1 (registro con rol admin, roles/permisos abiertos
 * a cualquier cliente, invitaciones sin auth) estaban cerrados POR LOS DOS
 * caminos. El camino viejo murio con Sanctum, asi que esos agujeros ya no se
 * cierran: NO EXISTEN. Lo que se prueba ahora es (1) que las rutas viejas no
 * estan, (2) que no queda ni codigo ni esquema del login local (criterio de
 * exito de la propuesta, tarea 9.9), y (3) que la unica superficie de
 * administracion que quedo (`zones`) sigue exigiendo exactamente `treslog:admin`.
 */
class Lote9RetiroTest extends TestCase
{
    use RefreshDatabase;

    private function delGateway(array $extra = []): array
    {
        return array_merge([
            'X-Auth-Gateway'  => 'myglobalhub-gateway',
            'X-User-Id'       => '4242',
            'X-User-Clerk-Id' => 'user_2abcClerk',
            'X-User-Email'    => 'jefa@tr3slog.test',
            'X-User-Name'     => 'Jefa de Operaciones',
            'X-User-Roles'    => 'treslog:admin',
        ], $extra);
    }

    /** @return list<string> */
    private function uris(): array
    {
        return array_map(static fn (RutaRegistrada $r): string => $r->uri(), Route::getRoutes()->getRoutes());
    }

    // =======================================================================
    //  1. Las rutas del login local, del RBAC local y de las invitaciones viejas
    // =======================================================================

    public function test_ninguna_ruta_de_autenticacion_local_existe(): void
    {
        $uris = $this->uris();

        foreach ([
            'api/register', 'api/login', 'api/logout', 'api/user',
            'api/forgot-password', 'api/verify-reset-token', 'api/reset-password',
            'api/driver/register', 'api/driver/login', 'api/driver/logout',
            'api/app/register', 'api/app/login', 'api/app/logout', 'api/app/user',
            'api/app/forgot-password', 'api/app/reset-password',
            'api/invitations/verify', 'api/invitations/accept',
            'api/treslog/invitations/send', 'api/treslog/invitations/pending',
            'api/treslog/roles', 'api/treslog/permissions',
            'api/roles', 'api/permissions',
            'sanctum/csrf-cookie',
        ] as $vieja) {
            $this->assertNotContains($vieja, $uris, "Volvio «{$vieja}»: el login local se retiro en el Lote 9.");
        }
    }

    public function test_las_rutas_viejas_responden_404_y_no_401(): void
    {
        // 404 y no 401: un 401 diria «autenticate» y no hay donde. La ruta no existe.
        foreach (['/api/login', '/api/register', '/api/driver/login', '/api/app/login', '/api/invitations/accept'] as $ruta) {
            $this->postJson($ruta, [])->assertStatus(404);
        }
        $this->getJson('/api/user')->assertStatus(404);
        $this->getJson('/api/treslog/roles')->assertStatus(404);
    }

    public function test_toda_ruta_de_api_es_del_gateway_o_del_camino_publico(): void
    {
        $fuera = [];
        foreach ($this->uris() as $uri) {
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            if (! str_starts_with($uri, 'api/treslog/')) {
                $fuera[] = $uri;
            }
        }

        $this->assertSame([], $fuera, "Rutas de api/ fuera del prefijo del gateway:\n    ".implode("\n    ", $fuera));
    }

    // =======================================================================
    //  2. Ni codigo ni esquema del login local (tarea 9.9)
    // =======================================================================

    public function test_no_queda_ni_hash_check_ni_create_token_ni_auth_sanctum_en_app_ni_routes(): void
    {
        $prohibidos = ['Hash::check', 'createToken(', 'auth:sanctum', 'currentAccessToken', 'Laravel\\Sanctum', 'HasApiTokens', 'Models\\Role', 'role_user', 'personal_access_tokens'];
        $hallazgos = [];

        $finder = (new Finder)->files()->in([base_path('app'), base_path('routes'), base_path('config'), base_path('bootstrap')])->name('*.php');
        foreach ($finder as $archivo) {
            $contenido = file_get_contents($archivo->getPathname());
            foreach ($prohibidos as $p) {
                // Se toleran menciones en comentarios que explican el retiro: lo
                // que se busca es CODIGO. Una linea que empieza con // o * no cuenta.
                foreach (explode("\n", $contenido) as $n => $linea) {
                    $sinEspacios = ltrim($linea);
                    if (str_starts_with($sinEspacios, '//') || str_starts_with($sinEspacios, '*') || str_starts_with($sinEspacios, '/*') || str_starts_with($sinEspacios, '|')) {
                        continue;
                    }
                    if (str_contains($linea, $p)) {
                        $hallazgos[] = str_replace(base_path().'/', '', $archivo->getPathname()).':'.($n + 1).'  '.trim($linea);
                    }
                }
            }
        }

        $this->assertSame([], $hallazgos, "Codigo del login local que volvio:\n    ".implode("\n    ", $hallazgos));
    }

    public function test_el_esquema_ya_no_tiene_ni_tablas_ni_columnas_de_autenticacion_local(): void
    {
        foreach (['roles', 'permissions', 'role_permission', 'role_user', 'personal_access_tokens', 'password_reset_tokens', 'user_invitations'] as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "La tabla «{$tabla}» volvio.");
        }
        foreach (['password', 'remember_token', 'reset_token', 'reset_token_expires'] as $columna) {
            $this->assertFalse(Schema::hasColumn('users', $columna), "La columna users.{$columna} volvio.");
        }
        $this->assertTrue(Schema::hasColumn('users', 'sso_roles'), 'Falta la foto de roles, que es lo que lista clientes.');
    }

    public function test_sanctum_no_esta_instalado(): void
    {
        $this->assertFalse(class_exists(\Laravel\Sanctum\Sanctum::class), 'laravel/sanctum sigue en vendor: falta `composer remove`.');
        $this->assertStringNotContainsString('laravel/sanctum', file_get_contents(base_path('composer.json')));
    }

    // =======================================================================
    //  3. La superficie de administracion que quedo sigue exigiendo treslog:admin
    // =======================================================================

    public function test_sin_cabeceras_del_gateway_la_administracion_responde_401_con_request_id(): void
    {
        $this->getJson('/api/treslog/zones')
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'request_id']);

        $this->withHeader('X-Request-Id', 'e1b2c3d4e5f60718293a4b5c6d7e8f90')
            ->getJson('/api/treslog/zones')
            ->assertStatus(401)
            ->assertJsonPath('request_id', 'e1b2c3d4e5f60718293a4b5c6d7e8f90');
    }

    public function test_treslog_customer_no_alcanza(): void
    {
        $this->withHeaders($this->delGateway(['X-User-Roles' => 'treslog:customer']))
            ->getJson('/api/treslog/zones')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * NGINX omite un `proxy_set_header` de valor vacio, asi que una persona sin
     * ningun rol de TR3SLOG llega SIN la cabecera. No es un error: es una
     * peticion valida de alguien que no tiene permiso.
     */
    public function test_sin_cabecera_de_roles_no_alcanza(): void
    {
        $cabeceras = $this->delGateway();
        unset($cabeceras['X-User-Roles']);

        $this->withHeaders($cabeceras)->getJson('/api/treslog/zones')->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    /** Control POSITIVO: sin el, los 403 de arriba podrian venir de una ruta mal montada. */
    public function test_treslog_admin_si_entra(): void
    {
        $this->withHeaders($this->delGateway())->getJson('/api/treslog/zones')->assertOk();
    }

    /**
     * `Super Admin` es rol de PLATAFORMA del SSO: viaja a todas las aplicaciones.
     * Si alcanzara para administrar TR3SLOG, el admin de cualquier otra
     * aplicacion administraria esta. Conceder de mas es el fallo que no se nota.
     */
    public function test_super_admin_no_alcanza_para_una_ruta_que_exige_treslog_admin(): void
    {
        $this->withHeaders($this->delGateway(['X-User-Roles' => 'Super Admin']))
            ->getJson('/api/treslog/zones')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /** El prefijo no es decoracion: es el limite entre inquilinos. */
    public function test_roles_de_otro_inquilino_o_sin_prefijo_no_alcanzan(): void
    {
        foreach ([
            'tienda:vendedor',
            'tienda:admin',
            'admin',                          // sin prefijo: el SSO no lo emite nunca
            'treslogadmin',
            'treslog:admins',
            'treslog:customer,tienda:admin',  // dos roles, ninguno sirve
        ] as $forjado) {
            $this->withHeaders($this->delGateway(['X-User-Roles' => $forjado]))
                ->getJson('/api/treslog/zones')
                ->assertStatus(403, "«{$forjado}» alcanzo para administrar TR3SLOG")
                ->assertJsonPath('error', 'forbidden');
        }
    }
}
