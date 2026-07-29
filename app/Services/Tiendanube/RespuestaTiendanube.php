<?php

namespace App\Services\Tiendanube;

/**
 * Resultado de una operación contra la API de Tiendanube, devuelto por
 * ClienteTiendanube::peticion(). Nunca lanza excepciones para errores del
 * proveedor — sólo para fallos irrecuperables del propio cliente (ver
 * Excepciones/).
 */
class RespuestaTiendanube
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
}
