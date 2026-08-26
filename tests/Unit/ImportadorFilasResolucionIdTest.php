<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Services\Import\ValidadorFilasImportacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Resolución alta/actualización por fila (spec 027) — se invoca por reflexión
 * porque `resolverModoFila()` es un helper privado, sin exponer una API
 * pública nueva sólo para testear (T008, research.md §2).
 *
 * Spec 083: se mudó de `ImportadorFilas` a `ValidadorFilasImportacion` — resolver alta
 * vs actualización es decidir, no escribir. Las expectativas no cambian.
 */
class ImportadorFilasResolucionIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{modo: string, registro?: Model, motivo?: string}
     */
    private function resolverModoFila(array $datos, string $entidad = 'clientes'): array
    {
        $validador = new ValidadorFilasImportacion;
        $metodo = new ReflectionMethod(ValidadorFilasImportacion::class, 'resolverModoFila');
        $metodo->setAccessible(true);

        return $metodo->invoke($validador, $datos, $entidad);
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
        // Spec 083 (contrato del validador, caso borde "Id no numérico"): el motivo pasa a nombrar
        // la columna en español, como el resto de los mensajes del importador.
        $this->assertSame('La columna Id tiene el valor «abc», que no es un id válido.', $resolucion['motivo']);
    }

    public function test_id_no_entero_es_fallida(): void
    {
        $this->assertSame('fallida', $this->resolverModoFila(['id' => '5,5'])['modo']);
        $this->assertSame('fallida', $this->resolverModoFila(['id' => '5.5'])['modo']);
    }
}
