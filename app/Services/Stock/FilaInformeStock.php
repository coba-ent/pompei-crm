<?php

namespace App\Services\Stock;

use Carbon\CarbonImmutable;

/** Una fila del `Informe Stock AAAA.xlsx` de Contagram, ya normalizada (spec 094). */
readonly class FilaInformeStock
{
    public function __construct(
        public ?int $idOperacion,
        public CarbonImmutable $fecha,
        public ?string $usuario,
        public string $operacion,
        public ?string $descripcion,
        public string $codigo,
        public float $cantidad,
        public string $deposito,
        public ?float $saldo,
        public int $anio,
        public int $fila,
    ) {}

    /** La operación es de venta (venta, nota de crédito de venta, o sus variantes eliminadas). */
    public function esDeVenta(): bool
    {
        return str_contains($this->operacion, 'Venta');
    }

    public function esDeCompra(): bool
    {
        return str_contains($this->operacion, 'Compra');
    }

    /** Una operación anulada en Contagram: viene con su contramovimiento y se netea sola. */
    public function esEliminada(): bool
    {
        return str_contains($this->operacion, 'liminad');
    }

    /** Texto para la descripción del movimiento; conserva el usuario de Contagram (FR-023). */
    public function textoDescripcion(): string
    {
        $partes = array_filter([
            $this->operacion,
            $this->descripcion,
            $this->usuario !== null ? "por {$this->usuario}" : null,
        ]);

        return mb_substr(implode(' — ', $partes), 0, 255);
    }
}
