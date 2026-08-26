<?php

namespace Tests\Unit;

use App\Services\Import\ValidadorFilasImportacion;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Capacidades de parseo por fila agregadas por spec 026: fecha (research.md §1)
 * y booleano (research.md §2). Se invocan por reflexión porque son helpers
 * privados, sin exponer una API pública nueva sólo para testear.
 *
 * Spec 083: estos helpers se mudaron de `ImportadorFilas` a
 * `ValidadorFilasImportacion` — el parseo es parte de decidir qué haría la fila, no de
 * escribirla. Las expectativas no cambian, sólo la clase que las cumple.
 */
class ImportadorFilasParseoTest extends TestCase
{
    private function validador(): ValidadorFilasImportacion
    {
        return new ValidadorFilasImportacion;
    }

    private function normalizarFecha(mixed $valor): ?string
    {
        $metodo = new ReflectionMethod(ValidadorFilasImportacion::class, 'normalizarFecha');
        $metodo->setAccessible(true);

        return $metodo->invoke($this->validador(), $valor);
    }

    private function normalizarBooleano(string $valor): ?bool
    {
        $metodo = new ReflectionMethod(ValidadorFilasImportacion::class, 'normalizarBooleano');
        $metodo->setAccessible(true);

        return $metodo->invoke($this->validador(), $valor);
    }

    public function test_normaliza_fecha_nativa_de_excel(): void
    {
        $this->assertSame('2026-03-15', $this->normalizarFecha(Carbon::create(2026, 3, 15)));
    }

    public function test_normaliza_fecha_texto_dd_mm_yyyy(): void
    {
        $this->assertSame('2026-03-15', $this->normalizarFecha('15/03/2026'));
    }

    public function test_normaliza_fecha_texto_yyyy_mm_dd(): void
    {
        $this->assertSame('2026-03-15', $this->normalizarFecha('2026-03-15'));
    }

    public function test_fecha_no_interpretable_devuelve_null(): void
    {
        $this->assertNull($this->normalizarFecha('no es una fecha'));
        $this->assertNull($this->normalizarFecha('32/13/2026'));
    }

    public function test_normaliza_booleano_si_no(): void
    {
        $this->assertTrue($this->normalizarBooleano('Si'));
        $this->assertTrue($this->normalizarBooleano('SÍ'));
        $this->assertFalse($this->normalizarBooleano('No'));
    }

    public function test_normaliza_booleano_1_0(): void
    {
        $this->assertTrue($this->normalizarBooleano('1'));
        $this->assertFalse($this->normalizarBooleano('0'));
    }

    public function test_normaliza_booleano_true_false(): void
    {
        $this->assertTrue($this->normalizarBooleano('TRUE'));
        $this->assertFalse($this->normalizarBooleano('false'));
    }

    public function test_booleano_no_reconocido_devuelve_null(): void
    {
        $this->assertNull($this->normalizarBooleano('tal vez'));
    }

    /**
     * Caso real 25/08/2026: ProductosExport escribe "Activo"/"Inactivo" en la
     * columna Estado, y reimportar ese mismo export a la columna "Activo" fallaba
     * porque estos valores no matcheaban ninguno de los tokens reconocidos.
     */
    public function test_normaliza_booleano_activo_inactivo(): void
    {
        $this->assertTrue($this->normalizarBooleano('Activo'));
        $this->assertFalse($this->normalizarBooleano('Inactivo'));
    }
}
