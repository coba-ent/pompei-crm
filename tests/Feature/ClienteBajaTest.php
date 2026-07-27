<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteBajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_estado_alterna_activo(): void
    {
        $cliente = Cliente::create(['nombre' => 'Toggle', 'activo' => true]);

        $response = $this->patchJson(route('clientes.estado', $cliente));

        $response->assertOk()->assertJsonPath('activo', false);
        $this->assertFalse($cliente->fresh()->activo);

        $this->patchJson(route('clientes.estado', $cliente))->assertJsonPath('activo', true);
    }

    public function test_destroy_elimina_cliente_sin_operaciones(): void
    {
        $cliente = Cliente::create(['nombre' => 'Borrable']);

        $response = $this->deleteJson(route('clientes.destroy', $cliente));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('clientes', ['id' => $cliente->id]);
    }

    public function test_destroy_rechaza_cliente_con_operaciones(): void
    {
        // Como tieneOperaciones() aún devuelve false para todos los clientes
        // reales, se prueba la salvaguarda invocando el controlador con un
        // Cliente mockeado que simula tener operaciones asociadas.
        $cliente = \Mockery::mock(Cliente::class)->makePartial();
        $cliente->shouldReceive('tieneOperaciones')->andReturn(true);
        $cliente->shouldReceive('delete')->never();

        $controller = new \App\Http\Controllers\ClienteController();
        $response = $controller->destroy($cliente);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($response->getData()->ok);
    }
}
