<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Services\OtrosIngresos\OtrosIngresos;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OtrosIngresosServiceTest extends TestCase
{
    use RefreshDatabase;

    private function categoria(): Categoria
    {
        return Categoria::create(['tipo' => 'ingreso', 'nombre' => 'Aportes', 'es_sistema' => false, 'activo' => true]);
    }

    public function test_crear_genera_movimiento_cobro_y_suma_el_saldo_exacto(): void
    {
        $categoria = $this->categoria();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $otroIngreso = app(OtrosIngresos::class)->crear([
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => '2026-07-18',
            'descripcion' => 'Aporte de socio',
        ]);

        $this->assertSame('registrado', $otroIngreso->estado);
        $this->assertDatabaseHas('movimientos_tesoreria', [
            'tipo' => 'cobro',
            'cuenta_destino_id' => $cuenta->id,
            'monto' => 5000.00,
            'origen_type' => \App\Models\OtroIngreso::class,
            'origen_id' => $otroIngreso->id,
        ]);
        $this->assertSame(6000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
    }

    public function test_anular_reversa_el_saldo_exacto_y_marca_anulado(): void
    {
        $categoria = $this->categoria();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $otroIngreso = app(OtrosIngresos::class)->crear([
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => '2026-07-18',
        ]);
        $this->assertSame(6000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));

        $anulado = app(OtrosIngresos::class)->anular($otroIngreso);

        $this->assertSame('anulado', $anulado->estado);
        $this->assertSame(1000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertDatabaseHas('otros_ingresos', ['id' => $otroIngreso->id, 'estado' => 'anulado', 'deleted_at' => null]);
    }

    public function test_anular_dos_veces_lanza_excepcion(): void
    {
        $categoria = $this->categoria();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $otroIngreso = app(OtrosIngresos::class)->crear([
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => '2026-07-18',
        ]);

        app(OtrosIngresos::class)->anular($otroIngreso);

        $this->expectException(RuntimeException::class);
        app(OtrosIngresos::class)->anular($otroIngreso);
    }
}
