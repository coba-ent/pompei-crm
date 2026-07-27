<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteFacturacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_campos_adicionales_se_guardan_dentro_del_cliente(): void
    {
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Con campos adicionales',
            'campos_personalizados' => [
                ['nombre' => 'Color', 'tipo' => 'opciones', 'opciones' => ['Rojo', 'Azul'], 'valor' => 'Rojo'],
                ['nombre' => 'Cumple', 'tipo' => 'fecha', 'valor' => '2026-07-23'],
                ['nombre' => '', 'tipo' => 'texto', 'valor' => 'x'], // sin nombre: se descarta
            ],
        ]);

        $response->assertOk();

        $cliente = Cliente::where('nombre', 'Con campos adicionales')->firstOrFail();
        $campos = $cliente->campos_personalizados;

        // Los campos viven DENTRO del cliente (nombre/tipo/opciones/valor), sin catálogo global.
        $this->assertCount(2, $campos);
        $this->assertSame('Color', $campos[0]['nombre']);
        $this->assertSame('opciones', $campos[0]['tipo']);
        $this->assertSame(['Rojo', 'Azul'], $campos[0]['opciones']);
        $this->assertSame('Rojo', $campos[0]['valor']);
        $this->assertSame('Cumple', $campos[1]['nombre']);
        $this->assertSame('2026-07-23', $campos[1]['valor']);
    }

    public function test_campos_adicionales_son_por_cliente_no_globales(): void
    {
        // Cliente A tiene un campo adicional...
        $this->postJson(route('clientes.store'), [
            'nombre' => 'Cliente A',
            'campos_personalizados' => [
                ['nombre' => 'Color', 'tipo' => 'texto', 'valor' => 'Rojo'],
            ],
        ])->assertOk();

        // ...y Cliente B, creado sin campos, NO hereda ninguno.
        $this->postJson(route('clientes.store'), ['nombre' => 'Cliente B'])->assertOk();

        $b = Cliente::where('nombre', 'Cliente B')->firstOrFail();
        $this->assertNull($b->campos_personalizados);
    }

    public function test_rechaza_cuit_con_dv_invalido(): void
    {
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Con CUIT malo',
            'cuit' => '20111111113',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['cuit']]);
        $this->assertSame(0, Cliente::count());
    }

    public function test_rechaza_cuit_duplicado_presente(): void
    {
        Cliente::create(['nombre' => 'Primero', 'cuit' => '20111111112']);

        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Segundo',
            'cuit' => '20111111112',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['cuit']]);
    }

    public function test_permite_varios_clientes_sin_cuit(): void
    {
        $this->postJson(route('clientes.store'), ['nombre' => 'A'])->assertOk();
        $this->postJson(route('clientes.store'), ['nombre' => 'B'])->assertOk();

        $this->assertSame(2, Cliente::whereNull('cuit')->count());
    }

    public function test_verificar_cuit_valido_devuelve_estructura(): void
    {
        $response = $this->postJson(route('clientes.verificar-cuit'), ['cuit' => '20111111112']);

        $response->assertOk()
            ->assertJsonPath('valido', true)
            ->assertJsonStructure(['valido', 'datos']);
    }

    public function test_verificar_cuit_invalido_devuelve_error(): void
    {
        $response = $this->postJson(route('clientes.verificar-cuit'), ['cuit' => '20111111113']);

        $response->assertOk()->assertJsonPath('valido', false);
    }

    public function test_condicion_iva_debe_existir(): void
    {
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'X',
            'condicion_iva_id' => 999,
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['condicion_iva_id']]);
    }

    public function test_dni_no_exige_validacion_de_cuit(): void
    {
        // Con tipo_documento DNI, un número que no es CUIT válido se acepta.
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Persona con DNI',
            'tipo_documento' => 'DNI',
            'cuit' => '12345678',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Persona con DNI', 'cuit' => '12345678']);
    }

    public function test_persiste_personas_de_contacto(): void
    {
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Empresa con contactos',
            'contactos' => [
                ['nombre' => 'Ana Gómez', 'apellido' => 'Compras', 'telefono' => '111', 'telefono_celular' => '222', 'email' => 'ana@e.com', 'enviar_mails' => 1],
                ['nombre' => '', 'apellido' => 'vacío'], // se descarta por no tener nombre
                ['nombre' => 'Luis Paz', 'email' => 'luis@e.com'],
            ],
        ]);

        $response->assertOk();
        $cliente = Cliente::where('nombre', 'Empresa con contactos')->firstOrFail();
        $this->assertCount(2, $cliente->contactos);
        $this->assertDatabaseHas('cliente_contactos', ['nombre' => 'Ana Gómez', 'apellido' => 'Compras', 'enviar_mails' => 1]);
    }

    public function test_actualizar_reemplaza_contactos(): void
    {
        $cliente = Cliente::create(['nombre' => 'Con contactos']);
        $cliente->contactos()->create(['nombre' => 'Viejo']);

        $this->patchJson(route('clientes.update', $cliente), [
            'nombre' => 'Con contactos',
            'contactos' => [['nombre' => 'Nuevo']],
        ])->assertOk();

        $this->assertDatabaseMissing('cliente_contactos', ['nombre' => 'Viejo']);
        $this->assertDatabaseHas('cliente_contactos', ['nombre' => 'Nuevo']);
    }

    public function test_eliminar_cliente_borra_sus_contactos(): void
    {
        $cliente = Cliente::create(['nombre' => 'Borrar']);
        $cliente->contactos()->create(['nombre' => 'Contacto']);

        $this->deleteJson(route('clientes.destroy', $cliente))->assertOk();

        $this->assertDatabaseMissing('cliente_contactos', ['cliente_id' => $cliente->id]);
    }

    public function test_cliente_apto_solo_con_condicion_iva(): void
    {
        $cf = CondicionIva::create(['nombre' => 'Consumidor Final', 'requiere_cuit' => false]);

        $cliente = Cliente::create(['nombre' => 'Apto', 'condicion_iva_id' => $cf->id]);
        $this->assertTrue($cliente->esAptoParaFacturar());

        $cliente->update(['condicion_iva_id' => null]);
        $this->assertFalse($cliente->fresh()->esAptoParaFacturar());
    }
}
