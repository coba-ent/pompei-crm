<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CondicionIva;
use App\Models\Empresa;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetencionTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(bool $retencionesHabilitadas): Empresa
    {
        $condicion = CondicionIva::create(['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true]);

        return Empresa::create([
            'razon_social' => 'Emisor de Prueba', 'cuit' => '20111111112',
            'condicion_iva_id' => $condicion->id, 'ambiente_arca' => 'testing',
            'retenciones_habilitadas' => $retencionesHabilitadas,
        ]);
    }

    public function test_caso_canonico_retencion_completa_el_cobro_parcial(): void
    {
        $this->empresa(true);
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        $cobro = Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 90000]);
        app(\App\Services\Ventas\Ventas::class)->recalcularEstadoCobro($venta);

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 10000, 'tipo_retencion' => 'IIBB',
        ])->assertCreated();

        $this->assertEquals(0.0, $cliente->fresh()->saldoCuentaCorriente());
        $this->assertSame('cobrado', $venta->fresh()->estado_cobro);
    }

    public function test_validaciones_de_retencion(): void
    {
        $this->empresa(true);
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        $cobro = Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 10000]);

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 0, 'tipo_retencion' => 'IIBB',
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 20000, 'tipo_retencion' => 'IIBB',
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->addDay()->toDateString(), 'monto' => 100, 'tipo_retencion' => 'IIBB',
        ])->assertStatus(422)->assertJsonValidationErrors('fecha');

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 100, 'tipo_retencion' => '',
        ])->assertStatus(422)->assertJsonValidationErrors('tipo_retencion');
    }

    /** T042 — el flag no debe entrar en el cálculo de saldos, sólo en el alta. */
    public function test_deshabilitar_la_funcion_rechaza_el_alta_pero_no_toca_saldos_historicos(): void
    {
        $this->empresa(true);
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        $cobro = Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 90000]);

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 10000, 'tipo_retencion' => 'IIBB',
        ])->assertCreated();

        $saldoConRetencion = $cliente->fresh()->saldoCuentaCorriente();
        $this->assertEquals(0.0, $saldoConRetencion);

        Empresa::actual()->update(['retenciones_habilitadas' => false]);

        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 100, 'tipo_retencion' => 'IIBB',
        ])->assertStatus(403);

        // El saldo histórico no cambia por apagar el flag.
        $this->assertEquals($saldoConRetencion, $cliente->fresh()->saldoCuentaCorriente());
    }

    /** T043 — eliminar un cobro con retenciones las elimina en cascada y recalcula el saldo. */
    public function test_eliminar_cobro_elimina_retenciones_en_cascada(): void
    {
        $this->empresa(true);
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        $cobro = Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 90000]);
        $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 10000, 'tipo_retencion' => 'IIBB',
        ])->assertCreated();

        app(\App\Services\Ventas\Ventas::class)->eliminarCobro($cobro->fresh());

        $this->assertDatabaseCount('retenciones', 0);
        $this->assertEquals(100000.0, $cliente->fresh()->saldoCuentaCorriente());
        $this->assertSame('pendiente', $venta->fresh()->estado_cobro);
    }

    /** T044 — eliminar una retención individual revierte el saldo y el estado del documento. */
    public function test_eliminar_retencion_revierte_saldo_y_estado(): void
    {
        $this->empresa(true);
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        $cobro = Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 90000]);
        $resp = $this->postJson(route('cobros.retenciones.store', $cobro), [
            'fecha' => now()->toDateString(), 'monto' => 10000, 'tipo_retencion' => 'IIBB',
        ])->assertCreated()->json();

        $this->assertEquals(0.0, $cliente->fresh()->saldoCuentaCorriente());
        $this->assertSame('cobrado', $venta->fresh()->estado_cobro);

        $this->deleteJson(route('retenciones.destroy', $resp['retencion']['id']))->assertOk();

        $this->assertEquals(10000.0, $cliente->fresh()->saldoCuentaCorriente());
        $this->assertSame('parcial', $venta->fresh()->estado_cobro);
    }
}
