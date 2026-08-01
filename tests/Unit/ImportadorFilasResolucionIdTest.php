<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Services\Import\ImportadorFilas;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Resolución alta/actualización por fila (spec 027) — se invoca por reflexión
 * porque `resolverModoFila()` es un helper privado de `ImportadorFilas`, sin
 * exponer una API pública nueva sólo para testear (T008, research.md §2).
 */
class ImportadorFilasResolucionIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{modo: string, registro?: \Illuminate\Database\Eloquent\Model, motivo?: string}
     */
    private function resolverModoFila(array $datos, string $entidad = 'clientes'): array
    {
        $importador = new ImportadorFilas(app(StockService::class));
        $metodo = new ReflectionMethod(ImportadorFilas::class, 'resolverModoFila');
        $metodo->setAccessible(true);

        return $metodo->invoke($importador, $datos, $entidad);
    }

    public function test_id_ausente_es_alta(): void
    {
        $this->assertSame('alta', $this->resolverModoFila([])['modo']);
    }

    public function test_id_vacio_es_alta(): void
    {
        $this->assertSame('alta', $this->resolverModoFila(['id' => ''])['modo']);
    }

    public function test_id_con_match_es_actualizacion(): void
    {
        $cliente = Cliente::factory()->create();

        $resolucion = $this->resolverModoFila(['id' => (string) $cliente->id]);

        $this->assertSame('actualizacion', $resolucion['modo']);
        $this->assertTrue($resolucion['registro']->is($cliente));
    }

    public function test_id_sin_match_es_alta_forzando_ese_id(): void
    {
        $resolucion = $this->resolverModoFila(['id' => '999999']);

        $this->assertSame('alta', $resolucion['modo']);
        $this->assertSame(999999, $resolucion['id']);
    }

    public function test_id_no_numerico_es_fallida(): void
    {
        $resolucion = $this->resolverModoFila(['id' => 'abc']);

        $this->assertSame('fallida', $resolucion['modo']);
        $this->assertSame('Id "abc" no es un id válido', $resolucion['motivo']);
    }

    public function test_id_no_entero_es_fallida(): void
    {
        $this->assertSame('fallida', $this->resolverModoFila(['id' => '5,5'])['modo']);
        $this->assertSame('fallida', $this->resolverModoFila(['id' => '5.5'])['modo']);
    }
}
