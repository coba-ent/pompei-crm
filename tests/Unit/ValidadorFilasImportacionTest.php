<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Import\ValidadorFilasImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 083 — el servicio que decide qué haría el importador con cada fila, sin hacerlo.
 *
 * Los casos son los del contrato (`contracts/validador-filas.md`, sección "Casos borde"). El que
 * sostiene todo lo demás —que el veredicto coincida fila por fila con lo que el importador aplica—
 * vive en `PrevalidacionImportacionTest`, porque necesita correr los dos caminos completos.
 */
class ValidadorFilasImportacionTest extends TestCase
{
    use RefreshDatabase;

    private function validador(): ValidadorFilasImportacion
    {
        return new ValidadorFilasImportacion;
    }

    /** @return array<string, mixed> */
    private function evaluar(array $celdas, string $entidad = 'clientes', array $mapeo = [0 => 'nombre'], array $columnas = ['Nombre']): array
    {
        return $this->validador()->evaluar($celdas, $entidad, $mapeo, [], $columnas);
    }

    public function test_fila_sin_id_es_alta(): void
    {
        $veredicto = $this->evaluar(['Cliente Uno']);

        $this->assertSame('alta', $veredicto['modo']);
        $this->assertNull($veredicto['registro_id']);
        $this->assertSame([], $veredicto['motivos']);
    }

    public function test_id_existente_es_actualizacion_con_el_registro_resuelto(): void
    {
        $cliente = Cliente::factory()->create();

        $veredicto = $this->evaluar(
            [(string) $cliente->id, 'Nombre Nuevo'],
            'clientes',
            [0 => 'id', 1 => 'nombre'],
            ['Id', 'Nombre'],
        );

        $this->assertSame('actualizacion', $veredicto['modo']);
        $this->assertSame($cliente->id, $veredicto['registro_id']);
    }

    /** Spec 027: un Id que no existe es un ALTA preservando ese id, no un error. */
    public function test_id_inexistente_es_alta_preservando_el_id(): void
    {
        $veredicto = $this->evaluar(
            ['9871', 'Cliente Nuevo'],
            'clientes',
            [0 => 'id', 1 => 'nombre'],
            ['Id', 'Nombre'],
        );

        $this->assertSame('alta', $veredicto['modo']);
        $this->assertSame(9871, $veredicto['id_forzado']);
    }

    public function test_id_no_numerico_es_error_nombrando_la_columna(): void
    {
        $veredicto = $this->evaluar(
            ['abc', 'Cliente'],
            'clientes',
            [0 => 'id', 1 => 'nombre'],
            ['Id', 'Nombre'],
        );

        $this->assertSame('error', $veredicto['modo']);
        $this->assertStringContainsString('Id', $veredicto['motivos'][0]);
        $this->assertStringContainsString('abc', $veredicto['motivos'][0]);
    }

    public function test_celda_no_numerica_en_campo_numerico_es_error(): void
    {
        $veredicto = $this->evaluar(
            ['Producto Uno', 'no-es-un-numero'],
            'productos',
            [0 => 'nombre', 1 => 'costo'],
            ['Nombre', 'Costo'],
        );

        $this->assertSame('error', $veredicto['modo']);
        $this->assertStringContainsString('Costo', implode(' ', $veredicto['motivos']));
    }

    /** I5: un Proveedor que no está en el catálogo avisa, pero no invalida la fila. */
    public function test_proveedor_inexistente_es_advertencia_y_no_error(): void
    {
        $veredicto = $this->evaluar(
            ['Producto Uno', 'Proveedor Que No Existe'],
            'productos',
            [0 => 'nombre', 1 => 'proveedor_id'],
            ['Nombre', 'Proveedor'],
        );

        $this->assertSame('alta', $veredicto['modo']);
        $this->assertSame([], $veredicto['motivos']);
        $this->assertStringContainsString('no encontrado', implode(' ', $veredicto['advertencias']));
    }

    public function test_proveedor_existente_se_resuelve_por_nombre(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Ferrum']);

        $veredicto = $this->evaluar(
            ['Producto Uno', 'ferrum'],
            'productos',
            [0 => 'nombre', 1 => 'proveedor_id'],
            ['Nombre', 'Proveedor'],
        );

        $this->assertSame('alta', $veredicto['modo']);
        $this->assertSame($proveedor->id, $veredicto['datos']['proveedor_id']);
    }

    /** Caso borde del contrato: una fila más corta que el encabezado no revienta. */
    public function test_fila_con_menos_celdas_que_el_encabezado_se_tolera(): void
    {
        $veredicto = $this->evaluar(
            ['Cliente Uno'],
            'clientes',
            [0 => 'nombre', 1 => 'email', 2 => 'telefono'],
            ['Nombre', 'Email', 'Teléfono'],
        );

        $this->assertSame('alta', $veredicto['modo']);
        $this->assertSame([], $veredicto['motivos']);
    }

    public function test_fila_vacia_falla_por_el_campo_obligatorio(): void
    {
        $veredicto = $this->evaluar(['']);

        $this->assertSame('error', $veredicto['modo']);
        $this->assertNotEmpty($veredicto['motivos']);
    }

    /**
     * I7 / FR-005b: `campos` es lo que el modal suma para decir "Costo: 100 registros". Si lista de
     * más, el modal miente sobre lo que la importación va a tocar.
     */
    public function test_campos_lista_solo_los_que_esta_fila_escribiria(): void
    {
        $producto = Producto::factory()->create();

        $veredicto = $this->evaluar(
            [(string) $producto->id, 'Producto Uno', '', '150'],
            'productos',
            [0 => 'id', 1 => 'nombre', 2 => 'descripcion', 3 => 'costo'],
            ['Id', 'Nombre', 'Descripción', 'Costo'],
        );

        $this->assertSame('actualizacion', $veredicto['modo']);
        // "Descripción" venía vacía: no se escribe, así que no se cuenta. "Id" no es un campo de
        // negocio que se escriba: tampoco.
        $this->assertSame(['Nombre', 'Costo'], $veredicto['campos']);
    }

    /** I1: el validador no tiene forma de escribir — ni siquiera con una fila perfectamente válida. */
    public function test_evaluar_no_escribe_nada_en_la_base(): void
    {
        $antes = Cliente::count();

        $this->evaluar(['Cliente Uno']);
        $this->evaluar(['Cliente Dos']);

        $this->assertSame($antes, Cliente::count());
    }
}
