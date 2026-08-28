<?php

namespace App\Services\Informes\Contador;

/**
 * Período del envío al contador (spec 087, data-model §3): `anio` obligatorio, `mes` opcional.
 * Concentra la distinción anual/mensual porque decide tres cosas a la vez: qué archivos
 * corresponden, cómo se llaman, y qué dice el cuerpo del correo — separarlas en condicionales
 * sueltos por el código es la fuente de divergencia que SC-004 prohíbe.
 */
class Periodo
{
    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(
        public readonly int $anio,
        public readonly ?int $mes = null,
    ) {}

    public function esMensual(): bool
    {
        return $this->mes !== null;
    }

    public function nombreMes(): string
    {
        return self::MESES[$this->mes] ?? throw new \InvalidArgumentException("Mes inválido: {$this->mes}");
    }

    /** "Marzo - 2026" (mensual) o "2026" (anual) — sufijo común de los 4 nombres de archivo (data-model §3). */
    public function sufijoNombre(): string
    {
        return $this->esMensual() ? "{$this->nombreMes()} - {$this->anio}" : (string) $this->anio;
    }

    /** "del mes de Marzo de 2026" o "del año 2026" — FR-014, corrige el hueco gramatical del original. */
    public function textoPeriodo(): string
    {
        return $this->esMensual()
            ? "del mes de {$this->nombreMes()} de {$this->anio}"
            : "del año {$this->anio}";
    }

    public function nombreIvaVentas(): string
    {
        return $this->esMensual() ? "IVA Ventas {$this->sufijoNombre()}.xlsx" : "IVA Ventas - {$this->sufijoNombre()}.xlsx";
    }

    public function nombreIvaCompras(): string
    {
        return $this->esMensual() ? "IVA Compras {$this->sufijoNombre()}.xlsx" : "IVA Compras - {$this->sufijoNombre()}.xlsx";
    }

    public function nombreIvaDigital(): string
    {
        return "IVA Digital {$this->sufijoNombre()}.zip";
    }

    public function nombrePdfsFacturas(): string
    {
        return "PDFs Facturas de Venta {$this->sufijoNombre()}.zip";
    }
}
