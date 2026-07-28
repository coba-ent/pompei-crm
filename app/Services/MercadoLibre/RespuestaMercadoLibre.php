<?php

namespace App\Services\MercadoLibre;

/**
 * Resultado de una operación contra la API de Mercado Libre, devuelto por
 * ClienteMercadoLibre::peticion(). Nunca lanza excepciones para errores del
 * proveedor — sólo para fallos irrecuperables del propio cliente (ver
 * Excepciones/).
 */
class RespuestaMercadoLibre
{
    public function __construct(
        public readonly bool $exito,
        public readonly bool $bloqueada = false,
        public readonly array $datos = [],
        public readonly ?int $codigoHttp = null,
        public readonly ?string $mensajeError = null,
        public readonly array $encabezados = [],
    ) {}

    public static function ok(array $datos, ?int $codigoHttp = 200, array $encabezados = []): self
    {
        return new self(exito: true, datos: $datos, codigoHttp: $codigoHttp, encabezados: $encabezados);
    }

    public static function error(string $mensaje, ?int $codigoHttp = null): self
    {
        return new self(exito: false, mensajeError: $mensaje, codigoHttp: $codigoHttp);
    }

    public static function bloqueada(string $mensaje): self
    {
        return new self(exito: false, bloqueada: true, mensajeError: $mensaje);
    }

    public function fueBloqueada(): bool
    {
        return $this->bloqueada;
    }

    public function fallo(): bool
    {
        return ! $this->exito;
    }

    /** Respuesta parcial de Mercado Libre (206 + `X-Content-Missing`, FR-012b). */
    public function esParcial(): bool
    {
        return $this->codigoHttp === 206;
    }

    /** Contenido crudo del header `X-Content-Missing` en una respuesta parcial (research.md §R2). */
    public function contenidoFaltante(): ?string
    {
        $valores = $this->encabezados['X-Content-Missing'] ?? $this->encabezados['x-content-missing'] ?? null;

        return $valores ? implode(', ', (array) $valores) : null;
    }
}
