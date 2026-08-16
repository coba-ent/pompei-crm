<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoAltaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas rutas están detrás del middleware `permiso:`, y el usuario que autentica
        // `Tests\TestCase` no trae ningún rol. Es el mismo `setUp` que ya usan
        // CompraVencidoTest y otros 140 archivos. No se centraliza en TestCase: varios tests
        // cuentan administradores o prueban la denegación, y adjuntarlo a todos rompe el
        // pivote `rol_usuario` y convierte esos asserts en falsos verdes. `syncWithoutDetaching`
        // y no `attach` porque algunos tests de estos mismos archivos ya lo adjuntan aparte.
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    private function categoria(): Categoria
    {
        return Categoria::create(['tipo' => 'gasto', 'nombre' => 'Alquiler']);
    }

    public function test_post_crea_gasto_pagado_y_resta_el_importe_de_la_cuenta(): void
    {
        $categoria = $this->categoria();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 10000]);

        $response = $this->postJson(route('gastos.store'), [
            'importe' => 5000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
            'descripcion' => 'Alquiler de julio',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('gastos', [
            'categoria_id' => $categoria->id,
            'importe' => 5000.00,
            'estado' => 'pagado',
        ]);

        $gasto = Gasto::first();
        $this->assertDatabaseHas('movimientos_tesoreria', [
            'tipo' => 'pago',
            'cuenta_origen_id' => $cuenta->id,
            'monto' => 5000.00,
            'origen_type' => Gasto::class,
            'origen_id' => $gasto->id,
        ]);
        $this->assertSame(5000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));

        $data = $this->getJson(route('gastos.data'));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsTotal'));
    }

    public function test_alta_pendiente_no_genera_movimiento_de_tesoreria(): void
    {
        $categoria = $this->categoria();

        $response = $this->postJson(route('gastos.store'), [
            'importe' => 3000,
            'categoria_id' => $categoria->id,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('gastos', ['estado' => 'pendiente', 'cuenta_tesoreria_id' => null]);
        $this->assertDatabaseCount('movimientos_tesoreria', 0);
    }
}
