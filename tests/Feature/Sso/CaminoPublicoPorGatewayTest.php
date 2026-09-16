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
}
