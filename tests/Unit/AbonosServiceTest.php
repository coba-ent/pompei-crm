<?php

namespace Tests\Unit;

use App\Models\Abono;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Services\Abonos\Abonos;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AbonosServiceTest extends TestCase
{
    use RefreshDatabase;

    private function abono(array $overrides = []): Abono
    {
        Categoria::create(['tipo' => 'ingreso', 'nombre' => 'Abono', 'es_sistema' => false, 'activo' => true]);
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        return Abono::create(array_merge([
            'cliente_id' => $cliente->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 3000,
            'frecuencia' => 'mensual',
            'dia_cobro' => 10,
            'proxima_fecha' => now()->toDateString(),
            'activo' => true,
        ], $overrides));
    }

    public function test_generar_ingreso_crea_otro_ingreso_asociado_y_avanza_proxima_fecha(): void
    {
        $abono = $this->abono();
        $proximaAnterior = $abono->proxima_fecha->copy();

        $otroIngreso = app(Abonos::class)->generarIngreso($abono, '2026-07');

        $this->assertNotNull($otroIngreso);
        $this->assertSame($abono->id, $otroIngreso->abono_id);
        $this->assertSame('2026-07', $otroIngreso->periodo);
        $this->assertSame(3000.0, app(Tesoreria::class)->saldoDe($abono->cuentaTesoreria->fresh()));
        $this->assertTrue($abono->fresh()->proxima_fecha->gt($proximaAnterior));
    }

    public function test_generar_ingreso_es_idempotente_por_abono_y_periodo(): void
    {
        $abono = $this->abono();

        $primero = app(Abonos::class)->generarIngreso($abono, '2026-07');
        $this->assertNotNull($primero);

        $segundo = app(Abonos::class)->generarIngreso($abono->fresh(), '2026-07');
        $this->assertNull($segundo);

        $this->assertDatabaseCount('otros_ingresos', 1);
        $this->assertSame(3000.0, app(Tesoreria::class)->saldoDe($abono->cuentaTesoreria->fresh()));
    }

    public function test_baja_detiene_la_generacion(): void
    {
        $abono = $this->abono();

        app(Abonos::class)->baja($abono);
        $this->assertFalse($abono->fresh()->activo);

        $this->expectException(RuntimeException::class);
        app(Abonos::class)->generarIngreso($abono->fresh(), '2026-07');
    }
}
