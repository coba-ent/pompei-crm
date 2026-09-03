<?php

namespace App\Services\Informes;

/**
 * Catálogo de dimensiones, medidas y agregadores del motor de tablas dinámicas (spec 069).
 *
 * Es la fuente de verdad compartida entre el backend —que valida lo que llega al guardar una
 * vista— y el front, que arma el pool de dimensiones y los selectores "Dato" y "Accion". Los
 * rótulos salen de acá para que la pantalla y el Excel exportado digan lo mismo.
 *
 * Ventas y Compras NO tienen el mismo conjunto: Compras no tiene vendedor, y donde Ventas cruza
 * por cliente, Compras cruza por proveedor (research R9). No se inventan dimensiones que el
 * modelo de Compras no tenga.
 */
class DimensionesPivot
{
    /**
     * Dimensiones por informe: clave interna => [rótulo visible, columna del dataset].
     *
     * La clave es la que viaja en `config.filas` / `config.columnas` de una vista guardada, así
     * que renombrarla invalida las vistas ya guardadas: si alguna vez hay que cambiarla, hace
     * falta migrar el JSON.
     */
    private const DIMENSIONES = [
        'ventas' => [
            'fecha_emision' => ['Fecha de Emisión', 'fecha'],
            'fecha_emision.anio' => ['Año', 'anio'],
            'fecha_emision.mes' => ['Mes', 'mes'],
            'categorias' => ['Categorías', 'categoria'],
            'clientes' => ['Clientes', 'cliente'],
            'tipos_factura' => ['Tipos de Factura', 'tipo_comprobante'],
            'vendedores' => ['Vendedores', 'vendedor'],
            'productos' => ['Productos', 'producto'],
            'producto_id' => ['Id de Producto', 'producto_id'],
            'codigo_producto' => ['Código de Producto', 'codigo'],
            'tipos_producto' => ['Tipos de Producto', 'tipo_producto'],
            'proveedores' => ['Proveedores', 'proveedor'],
            'cantidades' => ['Cantidades', 'cantidad'],
            'descuento_pct' => ['Descuento en %', 'descuento_pct'],
            'etiquetas' => ['Etiquetas', 'etiquetas'],
        ],
        'compras' => [
            'fecha_emision' => ['Fecha de Emisión', 'fecha'],
            'fecha_emision.anio' => ['Año', 'anio'],
            'fecha_emision.mes' => ['Mes', 'mes'],
            'categorias' => ['Categorías', 'categoria'],
            'proveedores' => ['Proveedores', 'proveedor'],
            'tipos_factura' => ['Tipos de Factura', 'tipo_comprobante'],
            'productos' => ['Productos', 'producto_servicio'],
            'tipos_producto' => ['Tipos de Producto', 'tipo_producto'],
            'cantidades' => ['Cantidades', 'cantidad'],
            'descuento_pct' => ['Descuento en %', 'descuento_pct'],
            'etiquetas' => ['Etiquetas', 'etiquetas'],
        ],
    ];

    /**
     * Medidas del selector "Dato" (FR-012, FR-012b): clave => [rótulo, columna, es_conteo].
     *
     * `es_conteo` decide si "Accion" se reduce a Suma (FR-014).
     *
     * El TOTAL DEL COMPROBANTE no está y no debe estar: se repite en cada línea del detalle, así
     * que sumarlo lo contaría una vez por ítem. "Cantidad de Ventas" resuelve esa necesidad
     * contando comprobantes distintos.
     */
    private const MEDIDAS = [
        'ventas' => [
            'total_venta' => ['Total Venta', 'total_venta', false],
            'total_venta_sin_impuestos' => ['Total Venta sin impuestos', 'precio_neto', false],
            'cantidad_productos' => ['Cantidad de Productos', 'cantidad', true],
            'cantidad_ventas' => ['Cantidad de Ventas', 'comprobante_id', true],
        ],
        'compras' => [
            'total_compra' => ['Total Compra', 'total_linea', false],
            'total_compra_sin_impuestos' => ['Total Compra sin impuestos', 'neto_linea', false],
            'cantidad_productos' => ['Cantidad de Productos', 'cantidad', true],
            'cantidad_compras' => ['Cantidad de Compras', 'comprobante_id', true],
        ],
    ];

    /**
     * Agregadores del selector "Accion" (FR-013): clave => [rótulo, sirve_para_conteo].
     *
     * Sobre un conteo de filas sólo tiene sentido sumar, así que las demás quedan fuera cuando el
     * Dato es un conteo (FR-014). Las tres fracciones se expresan en porcentaje (FR-016).
     */
    private const AGREGADORES = [
        'suma' => ['Suma', true],
        'promedio' => ['Promedio', false],
        'minimo' => ['Mínimo', false],
        'maximo' => ['Máximo', false],
        'fraccion_total' => ['Suma como Fracción del Total', false],
        'fraccion_fila' => ['Suma como Fracción por Línea', false],
        'fraccion_columna' => ['Suma como Fracción por Columna', false],
    ];

    private function validarInforme(string $informe): void
    {
        if (! isset(self::DIMENSIONES[$informe])) {
            throw new \InvalidArgumentException("Informe desconocido para el pivot: {$informe}");
        }
    }

    /** @return array<string, array{rotulo: string, columna: string}> */
    public function dimensiones(string $informe): array
    {
        $this->validarInforme($informe);

        return collect(self::DIMENSIONES[$informe])
            ->map(fn ($d) => ['rotulo' => $d[0], 'columna' => $d[1]])
            ->all();
    }

    /** @return array<string, array{rotulo: string, columna: string, es_conteo: bool}> */
    public function medidas(string $informe): array
    {
        $this->validarInforme($informe);

        return collect(self::MEDIDAS[$informe])
            ->map(fn ($m) => ['rotulo' => $m[0], 'columna' => $m[1], 'es_conteo' => $m[2]])
            ->all();
    }

    /**
     * Agregadores válidos. Si se pasa una medida, se filtra según FR-014.
     *
     * @return array<string, string> clave => rótulo
     */
    public function agregadores(string $informe, ?string $medida = null): array
    {
        $this->validarInforme($informe);

        $esConteo = $medida !== null && ($this->medidas($informe)[$medida]['es_conteo'] ?? false);

        return collect(self::AGREGADORES)
            ->filter(fn ($a) => ! $esConteo || $a[1])
            ->map(fn ($a) => $a[0])
            ->all();
    }

    /** ¿La combinación Dato + Acción es válida? Se valida al guardar, no sólo en el cliente. */
    public function combinacionValida(string $informe, string $medida, string $accion): bool
    {
        $this->validarInforme($informe);

        if (! isset(self::MEDIDAS[$informe][$medida])) {
            return false;
        }

        return array_key_exists($accion, $this->agregadores($informe, $medida));
    }

    /** Claves de dimensión válidas para ese informe, para validar `config.filas`/`columnas`. */
    public function clavesDimension(string $informe): array
    {
        return array_keys($this->dimensiones($informe));
    }
}
