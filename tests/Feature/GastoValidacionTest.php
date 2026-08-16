<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoValidacionTest extends TestCase
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
        return Categoria::create(['tipo' => 'gasto', 'nombre' => 'Servicios']);
    }

    public function test_rechaza_sin_importe(): void
    {
        $categoria = $this->categoria();

        $this->postJson(route('gastos.store'), [
            'categoria_id' => $categoria->id,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('importe');
    }

    public function test_rechaza_importe_no_positivo(): void
    {
        $categoria = $this->categoria();

        $this->postJson(route('gastos.store'), [
            'importe' => 0,
            'categoria_id' => $categoria->id,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('importe');
    }

    public function test_rechaza_sin_categoria(): void
    {
        $this->postJson(route('gastos.store'), [
            'importe' => 100,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('categoria_id');
    }

    public function test_rechaza_sin_fecha(): void
    {
        $categoria = $this->categoria();

        $this->postJson(route('gastos.store'), [
            'importe' => 100,
            'categoria_id' => $categoria->id,
            'estado' => 'pendiente',
        ])->assertStatus(422)->assertJsonValidationErrors('fecha');
    }

    public function test_rechaza_pagado_sin_cuenta(): void
    {
        $categoria = $this->categoria();

        $this->postJson(route('gastos.store'), [
            'importe' => 100,
            'categoria_id' => $categoria->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_tesoreria_id');
    }

    public function test_rechaza_subcategoria_ajena_a_la_categoria(): void
    {
        $categoria = $this->categoria();
        $otraCategoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Impuestos']);
        $subDeOtra = Categoria::create(['tipo' => 'gasto', 'nombre' => 'IIBB', 'categoria_padre_id' => $otraCategoria->id]);

        $this->postJson(route('gastos.store'), [
            'importe' => 100,
            'categoria_id' => $categoria->id,
            'subcategoria_id' => $subDeOtra->id,
            'estado' => 'pendiente',
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('subcategoria_id');
    }

    public function test_rechaza_cuenta_oculta(): void
    {
        $categoria = $this->categoria();
        $cuentaOculta = CuentaTesoreria::factory()->create(['activo' => false]);

        $this->postJson(route('gastos.store'), [
            'importe' => 100,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuentaOculta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_tesoreria_id');
    }
}
