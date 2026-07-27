<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteAltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_cliente_con_nombre_valido(): void
    {
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Comercio del Sur',
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('clientes', ['nombre' => 'Comercio del Sur', 'activo' => true]);
    }

    public function test_rechaza_alta_sin_nombre(): void
    {
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['errors' => ['nombre']]);

        $this->assertSame(0, Cliente::count());
    }

    public function test_saldo_inicial_vacio_no_rompe(): void
    {
        // El form siempre envía saldo_inicial; vacío llega como null por el
        // middleware ConvertEmptyStringsToNull y no debe violar el NOT NULL.
        $response = $this->postJson(route('clientes.store'), [
            'nombre' => 'Sin saldo',
            'saldo_inicial' => '',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Sin saldo', 'saldo_inicial' => 0]);
    }

    public function test_edita_cliente(): void
    {
        $cliente = Cliente::create(['nombre' => 'Original']);

        $response = $this->patchJson(route('clientes.update', $cliente), [
            'nombre' => 'Modificado',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'nombre' => 'Modificado']);
    }
}
