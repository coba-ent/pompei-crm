<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\LogAuditoria;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Ingresos\Cobranzas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Editar una cobranza (spec 053) debe generar 2 eventos de auditoría distintos, no uno ni duplicados. */
class AuditoriaEditarCobranzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_editar_cobranza_genera_evento_de_cobro_y_de_movimiento_tesoreria(): void
    {
        $venta = Venta::factory()->create(['total' => 1000]);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();
        $cuentaNueva = CuentaTesoreria::factory()->tipo('banco')->create();

        $cobranzas = app(Cobranzas::class);
        $cobro = $cobranzas->registrarCobro($venta, 500, $cuenta, now());

        $antes = LogAuditoria::count();

        $cobranzas->actualizarCobro($cobro, 600, $cuentaNueva, now());

        $this->assertSame($antes + 2, LogAuditoria::count());

        $eventosNuevos = LogAuditoria::orderByDesc('id')->limit(2)->get();
        $this->assertEqualsCanonicalizing(
            ['cobro', 'movimiento_tesoreria'],
            $eventosNuevos->pluck('tipo_operacion')->toArray()
        );
        $this->assertTrue($eventosNuevos->every(fn (LogAuditoria $log) => $log->tipo_accion === 'modifico'));
    }
}
