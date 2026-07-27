<?php

namespace Tests\Feature;

use App\Models\CondicionIva;
use App\Models\Empresa;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US5: config de campos visible/obligatorio de Nueva Venta / Nuevo Presupuesto (FR-026/027/028). */
class AjustesFormularioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $condicion = CondicionIva::create(['nombre' => 'Responsable Inscripto']);
        Empresa::create([
            'razon_social' => 'Emisor de Prueba',
            'cuit' => '20111111112',
            'condicion_iva_id' => $condicion->id,
            'ambiente_arca' => 'testing',
        ]);
    }

    public function test_guarda_config_de_campos_de_venta(): void
    {
        $this->putJson(route('configuracion.ajustes.form-venta'), [
            'lista_precio_id' => ['visible' => true, 'obligatorio' => true],
            'percepciones' => ['visible' => false, 'obligatorio' => false],
        ])->assertOk();

        $this->assertEquals(
            ['visible' => true, 'obligatorio' => true],
            Empresa::actual()->config_form_venta['lista_precio_id']
        );
    }

    public function test_campo_marcado_obligatorio_pasa_a_ser_requerido_al_crear_una_venta(): void
    {
        $this->putJson(route('configuracion.ajustes.form-venta'), [
            'lista_precio_id' => ['visible' => true, 'obligatorio' => true],
        ])->assertOk();

        $cliente = \App\Models\Cliente::factory()->create();

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'lineas' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['lista_precio_id', 'lineas']);
    }

    public function test_guarda_config_de_campos_de_presupuesto(): void
    {
        $this->putJson(route('configuracion.ajustes.form-presupuesto'), [
            'fecha_vencimiento' => ['visible' => true, 'obligatorio' => true],
        ])->assertOk();

        $this->assertTrue(Empresa::actual()->config_form_presupuesto['fecha_vencimiento']['obligatorio']);
    }
}
