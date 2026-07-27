<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtrosIngresosTest extends TestCase
{
    use RefreshDatabase;

    private function categoriaIngreso(string $nombre = 'Aportes', bool $esSistema = false): Categoria
    {
        return Categoria::create(['tipo' => 'ingreso', 'nombre' => $nombre, 'es_sistema' => $esSistema, 'activo' => true]);
    }

    public function test_post_crea_otro_ingreso_valido_y_suma_la_cuenta(): void
    {
        $categoria = $this->categoriaIngreso();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $response = $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => '2026-07-18',
            'descripcion' => 'Aporte de socio',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('otros_ingresos', [
            'categoria_id' => $categoria->id,
            'monto' => 5000.00,
            'estado' => 'registrado',
        ]);
        $this->assertSame(6000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
    }

    public function test_rechaza_sin_cuenta(): void
    {
        $categoria = $this->categoriaIngreso();

        $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'monto' => 5000,
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_tesoreria_id');
    }

    public function test_rechaza_sin_monto(): void
    {
        $categoria = $this->categoriaIngreso();
        $cuenta = CuentaTesoreria::factory()->create();

        $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('monto');
    }

    public function test_rechaza_monto_no_positivo(): void
    {
        $categoria = $this->categoriaIngreso();
        $cuenta = CuentaTesoreria::factory()->create();

        $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 0,
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('monto');
    }

    public function test_rechaza_fecha_futura(): void
    {
        $categoria = $this->categoriaIngreso();
        $cuenta = CuentaTesoreria::factory()->create();

        $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1000,
            'fecha' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('fecha');
    }

    public function test_carga_saldo_inicial_con_categoria_de_sistema(): void
    {
        $saldoInicial = $this->categoriaIngreso('Saldo Inicial', true);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $saldoInicial->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 25000,
            'fecha' => '2026-07-18',
        ])->assertOk();

        $this->assertSame(25000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
    }

    public function test_anular_devuelve_el_saldo_y_bloquea_doble_anulacion(): void
    {
        $categoria = $this->categoriaIngreso();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $store = $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 4000,
            'fecha' => '2026-07-18',
        ]);
        $otroIngresoId = $store->json('otroIngreso.id');
        $this->assertSame(5000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));

        $this->deleteJson(route('otros-ingresos.destroy', $otroIngresoId))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertDatabaseHas('otros_ingresos', ['id' => $otroIngresoId, 'estado' => 'anulado']);

        $this->deleteJson(route('otros-ingresos.destroy', $otroIngresoId))->assertStatus(409);
    }

    public function test_update_solo_permite_campos_no_financieros(): void
    {
        $categoria = $this->categoriaIngreso();
        $otraCategoria = $this->categoriaIngreso('Intereses');
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $store = $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 4000,
            'fecha' => '2026-07-18',
        ]);
        $otroIngresoId = $store->json('otroIngreso.id');

        $this->putJson(route('otros-ingresos.update', $otroIngresoId), [
            'categoria_id' => $otraCategoria->id,
            'descripcion' => 'Actualizado',
        ])->assertOk();

        $this->assertDatabaseHas('otros_ingresos', [
            'id' => $otroIngresoId,
            'categoria_id' => $otraCategoria->id,
            'descripcion' => 'Actualizado',
            'monto' => 4000.00,
        ]);
        $this->assertSame(5000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
    }

    // ==================================================================
    // Categorías de ingreso (US3)
    // ==================================================================

    public function test_crea_categoria_de_ingreso(): void
    {
        $this->postJson(route('otros-ingresos.categorias.store'), ['nombre' => 'Intereses bancarios'])
            ->assertStatus(201)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('categorias', [
            'tipo' => 'ingreso', 'nombre' => 'Intereses bancarios', 'es_sistema' => false, 'activo' => true,
        ]);
    }

    public function test_rechaza_nombre_de_categoria_duplicado(): void
    {
        $this->categoriaIngreso('Intereses bancarios');

        $this->postJson(route('otros-ingresos.categorias.store'), ['nombre' => 'Intereses bancarios'])
            ->assertStatus(422);
    }

    public function test_no_elimina_categoria_de_sistema(): void
    {
        $saldoInicial = $this->categoriaIngreso('Saldo Inicial', true);

        $this->deleteJson(route('otros-ingresos.categorias.destroy', $saldoInicial))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseHas('categorias', ['id' => $saldoInicial->id]);
    }

    public function test_no_elimina_categoria_con_ingresos_asociados(): void
    {
        $categoria = $this->categoriaIngreso();
        $cuenta = CuentaTesoreria::factory()->create();

        $this->postJson(route('otros-ingresos.store'), [
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1000,
            'fecha' => '2026-07-18',
        ])->assertOk();

        $this->deleteJson(route('otros-ingresos.categorias.destroy', $categoria))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_desactiva_categoria_sin_eliminarla(): void
    {
        $categoria = $this->categoriaIngreso();

        $this->patchJson(route('otros-ingresos.categorias.estado', $categoria), ['activo' => false])
            ->assertOk();

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'activo' => false]);
    }

    public function test_no_renombra_categoria_de_sistema(): void
    {
        $saldoInicial = $this->categoriaIngreso('Saldo Inicial', true);

        $this->putJson(route('otros-ingresos.categorias.update', $saldoInicial), ['nombre' => 'Otro nombre'])
            ->assertStatus(422);

        $this->assertDatabaseHas('categorias', ['id' => $saldoInicial->id, 'nombre' => 'Saldo Inicial']);
    }
}
