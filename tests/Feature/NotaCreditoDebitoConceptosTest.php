<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 061 US1: conceptos (percepciones/impuestos internos/intereses) en NC/ND. */
class NotaCreditoDebitoConceptosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(): Venta
    {
        $cliente = Cliente::factory()->create();

        return Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
    }

    public function test_crear_nota_con_conceptos_persiste_impuestos_y_monto_incluye_la_suma(): void
    {
        $venta = $this->crearVenta();

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Con percepción e interés',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1330.50,
            'conceptos' => [
                ['tipo' => 'percepcion', 'concepto' => 'IIBB Buenos Aires', 'monto' => 1250.50],
                ['tipo' => 'interes', 'concepto' => 'Interés por mora', 'monto' => 80],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertSame(1330.50, (float) $nota->monto);
        $this->assertCount(2, $nota->impuestos);
        $this->assertSame('IIBB Buenos Aires', $nota->impuestos[0]['concepto']);
        $this->assertSame('percepcion', $nota->impuestos[0]['tipo']);
        $this->assertSame(1250.50, (float) $nota->impuestos[0]['monto']);
    }

    public function test_editar_nota_agregando_y_quitando_conceptos_actualiza_impuestos(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1100,
            'conceptos' => [
                ['tipo' => 'impuesto_interno', 'concepto' => 'Combustibles', 'monto' => 100],
            ],
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertCount(1, $nota->impuestos);

        $response = $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Corregido',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1380.50,
            'conceptos' => [
                ['tipo' => 'percepcion', 'concepto' => 'IIBB Buenos Aires', 'monto' => 1250.50],
                ['tipo' => 'interes', 'concepto' => 'Interés por mora', 'monto' => 30],
            ],
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $nota->refresh();
        $this->assertCount(2, $nota->impuestos);
        $this->assertSame('percepcion', $nota->impuestos[0]['tipo']);
        $this->assertSame('interes', $nota->impuestos[1]['tipo']);

        // Quitar conceptos: omitir el campo del payload vacía la lista.
        $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Sin conceptos',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
        ])->assertOk();

        $this->assertSame([], $nota->fresh()->impuestos);
    }

    public function test_fila_de_concepto_sin_concepto_no_se_persiste(): void
    {
        $venta = $this->crearVenta();

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Fila incompleta',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'conceptos' => [
                ['tipo' => 'percepcion', 'monto' => 50],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('conceptos.0.concepto');
    }

    public function test_eliminar_nota_con_conceptos_cargados_no_deja_registros_huerfanos(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Con conceptos',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1150,
            'conceptos' => [
                ['tipo' => 'percepcion', 'concepto' => 'IIBB CABA', 'monto' => 150],
            ],
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $this->deleteJson(route('ventas.notas.destroy', [$venta, $nota]))->assertOk();

        $this->assertSoftDeleted('notas_credito_debito', ['id' => $nota->id]);
    }
}
