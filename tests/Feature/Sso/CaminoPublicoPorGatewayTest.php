<?php

namespace Tests\Feature\Sso;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D8.1 cerrado: las tres rutas publicas tienen su camino por el gateway,
 * /api/treslog/public/*, sin identidad y con cupo por IP. Y SOLO esas tres.
 */
class CaminoPublicoPorGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_las_tres_rutas_publicas_viven_bajo_treslog_public(): void
    {
        $publicas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/treslog/public/'))
            ->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())
            ->sort()->values()->all();

        $this->assertSame([
            'GET api/treslog/public/app/quotes/track/{tracking_code}',
            'POST api/treslog/public/app/quotes',
            'POST api/treslog/public/contact',
        ], $publicas);

        foreach (Route::getRoutes()->getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/treslog/public/')) {
                $this->assertNotContains('gateway.auth', $r->gatherMiddleware(), $r->uri().' no deberia exigir identidad');
                $this->assertContains('throttle:30,1', $r->gatherMiddleware(), $r->uri().' sin cupo');
            }
        }
    }

    public function test_el_tracking_publico_responde_sin_identidad(): void
    {
        $this->getJson('/api/treslog/public/app/quotes/track/NO-EXISTE')->assertStatus(404);
        // Y el mismo camino con identidad exigida sigue cerrado sin token.
        $this->getJson('/api/treslog/quotes')->assertStatus(401);
    }

    public function test_el_cupo_por_ip_responde_429_controlado(): void
    {
        foreach (range(1, 30) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->getJson('/api/treslog/public/app/quotes/track/X'.$i);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->getJson('/api/treslog/public/app/quotes/track/X31')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.8'])->getJson('/api/treslog/public/app/quotes/track/Y')->assertStatus(404);
    }

    public function test_el_tracking_publico_no_expone_datos_del_cliente(): void
    {
        // Revision de jueces 2026-09-16 (#4): la fila entera salia por una ruta
        // publica con codigo secuencial. Lo que la pantalla muestra, y nada mas.
        $quote = \App\Models\Quote::forceCreate([
            'origin' => 'SDQ', 'destination' => 'STI', 'service_type' => 'express', 'status' => 'pending',
            'client_name' => 'Ana Privada', 'client_email' => 'ana.privada@ejemplo.com', 'details' => 'caja fragil, llamar antes',
            'tracking_code' => 'TR3S-160926-SDQ-T000001',
        ]);

        $r = $this->getJson('/api/treslog/public/app/quotes/track/TR3S-160926-SDQ-T000001')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['tracking_code', 'status', 'origin', 'destination', 'service_type', 'created_at', 'updated_at'],
            array_keys($r->json())
        );
        foreach (['Ana Privada', 'ana.privada@ejemplo.com', 'caja fragil'] as $privado) {
            $this->assertStringNotContainsString($privado, $r->getContent());
        }
        $this->assertSame('pending', $r->json('status'));
        $this->assertSame((string) $quote->id, (string) $quote->id); // la fila existe; el id no viaja
        $this->assertArrayNotHasKey('id', $r->json());
    }

    public function test_el_tracking_tiene_un_cupo_mas_corto_que_el_resto_del_camino_publico(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.7.7.7'])->getJson('/api/treslog/public/app/quotes/track/Z'.$i)->assertStatus(404);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.7.7.7'])->getJson('/api/treslog/public/app/quotes/track/Z11')->assertStatus(429);
        // El contacto sigue con el cupo general desde la misma IP.
        $this->withServerVariables(['REMOTE_ADDR' => '10.7.7.7'])->postJson('/api/treslog/public/contact', [])->assertStatus(422);
    }
}
