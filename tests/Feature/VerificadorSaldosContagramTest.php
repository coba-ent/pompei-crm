<?php

namespace Tests\Feature;

use App\Services\Stock\VerificadorSaldosContagram;
use Tests\TestCase;

/**
 * La verificación independiente contra el `Saldo Stock` del export (spec 094, FR-016).
 *
 * `Saldo Stock` es el TOTAL del inventario, no el saldo del producto — filas consecutivas de
 * productos distintos traen valores contiguos (1368, 1369, 1370). Interpretarlo como saldo por
 * producto produce 28.391 discrepancias sobre 28.853 comparaciones: ruido puro.
 */
class VerificadorSaldosContagramTest extends TestCase
{
    /** @return array{cantidad: float, saldo: float|null, fila: int, operacion: string, codigo: string} */
    private function fila(float $cantidad, ?float $saldo, int $numero = 5): array
    {
        return [
            'cantidad' => $cantidad, 'saldo' => $saldo, 'fila' => $numero,
            'operacion' => 'Venta', 'codigo' => '28379 TAPA',
        ];
    }

    /**
     * El export va del más nuevo al más viejo, así que `saldo[i-1] - saldo[i] == cantidad[i-1]`.
     * Verificado sobre el archivo 2024 real: 14.004 pares, cero excepciones.
     */
    public function test_una_cadena_coherente_no_reporta_discrepancias(): void
    {
        $resultado = (new VerificadorSaldosContagram)->verificar([
            $this->fila(-1, 1368, 5),
            $this->fila(-1, 1369, 6),
            $this->fila(4, 1370, 7),
            $this->fila(-1, 1366, 8),
        ]);

        $this->assertSame(3, $resultado['comparados']);
        $this->assertSame([], $resultado['discrepancias']);
    }

    /** Si una cantidad se leyó mal, la cadena se rompe justo ahí y el informe da la fila. */
    public function test_detecta_una_cantidad_leida_mal_y_senala_la_fila(): void
    {
        $resultado = (new VerificadorSaldosContagram)->verificar([
            $this->fila(-1, 1368, 5),
            $this->fila(-7, 1369, 6),
            $this->fila(4, 1368, 7),
        ]);

        // Entre el saldo de la fila 6 (1369) y el de la 7 (1368) el inventario bajó 1, pero la
        // fila 6 dice -7. La cadena se rompe exactamente ahí.
        $this->assertCount(1, $resultado['discrepancias']);
        $this->assertSame(6, $resultado['discrepancias'][0]['fila']);
        $this->assertSame(-7.0, $resultado['discrepancias'][0]['cantidad_leida']);
        $this->assertSame(1.0, $resultado['discrepancias'][0]['delta_del_saldo']);
    }

    /** Un signo invertido es el error más peligroso: rompe la cadena y se detecta. */
    public function test_detecta_un_signo_invertido(): void
    {
        $resultado = (new VerificadorSaldosContagram)->verificar([
            $this->fila(1, 1368, 5),
            $this->fila(-1, 1369, 6),
        ]);

        $this->assertCount(1, $resultado['discrepancias']);
    }

    /**
     * Una fila sin saldo se saltea, y la comparación pasa a ser entre las dos que la rodean: el
     * delta abarca los dos movimientos. Se compara igual, con la cantidad de la primera.
     */
    public function test_ignora_las_filas_sin_saldo(): void
    {
        $resultado = (new VerificadorSaldosContagram)->verificar([
            $this->fila(-1, 1368, 5),
            $this->fila(-1, null, 6),
            $this->fila(-1, 1369, 7),
        ]);

        $this->assertSame(1, $resultado['comparados'], 'La fila sin saldo no participa.');
        $this->assertSame([], $resultado['discrepancias']);
    }

    /** Las filas de cantidad 0 son parte de la cadena: mantienen el saldo. */
    public function test_las_filas_en_cero_mantienen_el_saldo(): void
    {
        $resultado = (new VerificadorSaldosContagram)->verificar([
            $this->fila(0, 574, 5),
            $this->fila(0, 574, 6),
            $this->fila(2, 574, 7),
        ]);

        $this->assertSame(2, $resultado['comparados']);
        $this->assertSame([], $resultado['discrepancias']);
    }
}
