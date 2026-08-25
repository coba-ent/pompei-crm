<?php

namespace App\Services\Import;

use App\Models\Categoria;
use App\Models\CondicionIva;
use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\Proveedor;
use App\Models\TipoProducto;

/**
 * Diccionario de campos destino ofrecidos por el paso de mapeo, por entidad
 * (data-model.md). No es una tabla — describe QUÉ campos existen para
 * Clientes/Proveedores/Productos y cómo resolver los que son FK-por-nombre
 * (research.md §3).
 */
class DefinicionCamposImportables
{
    /**
     * @return array<string, array{etiqueta: string, obligatorio: bool, fk?: array{modelo: class-string, scope?: string}, default?: mixed}>
     */
    public static function clientes(): array
    {
        return [
            'id' => ['etiqueta' => 'Id', 'obligatorio' => false, 'id' => true],
            'nombre' => ['etiqueta' => 'Cliente (Nombre)', 'obligatorio' => true],
            'nombre_pila' => ['etiqueta' => 'Nombre', 'obligatorio' => false],
            'apellido' => ['etiqueta' => 'Apellido', 'obligatorio' => false],
            'telefono' => ['etiqueta' => 'Teléfono', 'obligatorio' => false],
            'telefono_celular' => ['etiqueta' => 'Teléfono Celular', 'obligatorio' => false],
            'email' => ['etiqueta' => 'Email', 'obligatorio' => false],
            'domicilio' => ['etiqueta' => 'Domicilio', 'obligatorio' => false],
            'localidad' => ['etiqueta' => 'Localidad', 'obligatorio' => false],
            'provincia' => ['etiqueta' => 'Provincia', 'obligatorio' => false],
            'cuit' => ['etiqueta' => 'CUIT', 'obligatorio' => false],
            'condicion_iva_id' => ['etiqueta' => 'Condición de IVA', 'obligatorio' => false, 'fk' => ['modelo' => CondicionIva::class]],
            'categoria_id' => ['etiqueta' => 'Categoría Ventas', 'obligatorio' => false, 'fk' => ['modelo' => Categoria::class, 'scope' => 'venta']],
            'nota' => ['etiqueta' => 'Nota', 'obligatorio' => false],
            'razon_social' => ['etiqueta' => 'Razón Social', 'obligatorio' => false],
            'tipo_documento' => ['etiqueta' => 'Tipo de Documento', 'obligatorio' => false],
            'domicilio_fiscal' => ['etiqueta' => 'Domicilio Fiscal', 'obligatorio' => false],
            'localidad_fiscal' => ['etiqueta' => 'Localidad Fiscal', 'obligatorio' => false],
            'provincia_fiscal' => ['etiqueta' => 'Provincia Fiscal', 'obligatorio' => false],
            'cp_fiscal' => ['etiqueta' => 'Código Postal Fiscal', 'obligatorio' => false],
            'telefono_fiscal' => ['etiqueta' => 'Teléfono Fiscal', 'obligatorio' => false],
            'telefono_celular_fiscal' => ['etiqueta' => 'Teléfono Celular Fiscal', 'obligatorio' => false],
            'cp' => ['etiqueta' => 'Código Postal', 'obligatorio' => false],
            'pagina_web' => ['etiqueta' => 'Página Web', 'obligatorio' => false],
            'saldo_inicial' => ['etiqueta' => 'Saldo Inicial', 'obligatorio' => false, 'numerico' => true],
            'saldo_inicial_fecha' => ['etiqueta' => 'Fecha de Saldo Inicial', 'obligatorio' => false, 'fecha' => true],
            'nota_cliente' => ['etiqueta' => 'Nota para Ventas', 'obligatorio' => false],
            'descuento_general_pct' => ['etiqueta' => 'Descuento General', 'obligatorio' => false, 'numerico' => true],
            'lista_precio_id' => ['etiqueta' => 'Lista de Precios', 'obligatorio' => false, 'fk' => ['modelo' => ListaPrecio::class]],
            'apodo_ml' => ['etiqueta' => 'Usuario de Mercado Libre', 'obligatorio' => false],
        ];
    }

    /**
     * Mismo diccionario que Clientes, con las diferencias ya vigentes desde
     * 003-proveedores-informe-stock: "Categoría Compras" en vez de "Categoría
     * Ventas", "Nota Interna" en vez de "Nota".
     *
     * @return array<string, array{etiqueta: string, obligatorio: bool, fk?: array{modelo: class-string, scope?: string}, default?: mixed}>
     */
    public static function proveedores(): array
    {
        $campos = self::clientes();
        $campos['categoria_id']['etiqueta'] = 'Categoría Compras';
        $campos['categoria_id']['fk']['scope'] = 'compra';
        $campos['nota']['etiqueta'] = 'Nota Interna';

        // No existen en Proveedor (data-model.md §Proveedores).
        unset($campos['apodo_ml'], $campos['nota_cliente'], $campos['descuento_general_pct'], $campos['lista_precio_id']);

        return $campos;
    }

    /**
     * @return array<string, array{etiqueta: string, obligatorio: bool, fk?: array{modelo: class-string, scope?: string}, default?: mixed}>
     */
    public static function productos(): array
    {
        $campos = [
            'id' => ['etiqueta' => 'Id', 'obligatorio' => false, 'id' => true],
            'nombre' => ['etiqueta' => 'Nombre', 'obligatorio' => true],
            'codigo' => ['etiqueta' => 'Código/SKU', 'obligatorio' => false],
            'tipo' => ['etiqueta' => 'Tipo', 'obligatorio' => false, 'default' => 'producto'],
            'tipo_producto_id' => ['etiqueta' => 'Tipo de Producto', 'obligatorio' => false, 'fk' => ['modelo' => TipoProducto::class]],
            'proveedor_id' => ['etiqueta' => 'Proveedor', 'obligatorio' => false, 'fk' => ['modelo' => Proveedor::class]],
            'precio_venta' => ['etiqueta' => 'Precio de Venta', 'obligatorio' => false, 'numerico' => true],
            'costo' => ['etiqueta' => 'Costo', 'obligatorio' => false, 'numerico' => true],
            'iva_venta_pct' => ['etiqueta' => 'IVA Ventas', 'obligatorio' => false],
            'iva_compra_pct' => ['etiqueta' => 'IVA Compras', 'obligatorio' => false],
            'descripcion' => ['etiqueta' => 'Descripción', 'obligatorio' => false],
            // Alias 'Punto Reposición': es como se llamaba la lista de precios de la que se
            // migró este dato (migracion:punto-reposicion), y así viene en exports viejos.
            'punto_reposicion' => ['etiqueta' => 'Punto de Reposición', 'obligatorio' => false, 'numerico' => true, 'alias' => ['Punto Reposición', 'Punto de reposicion']],
            'activo' => ['etiqueta' => 'Activo', 'obligatorio' => false, 'booleano' => true],
            'mostrar_en_ventas' => ['etiqueta' => 'Mostrar en Ventas', 'obligatorio' => false, 'booleano' => true],
            'mostrar_en_compras' => ['etiqueta' => 'Mostrar en Compras', 'obligatorio' => false, 'booleano' => true],
        ];

        // Una entrada de mapeo por cada lista de precios activa (equivalente a las
        // "columnas dinámicas" ya documentadas para el listado de Productos), para que
        // el importador pueda cargar el precio de cada producto en esa lista sin
        // requerir una carga manual posterior — ver precios_producto.
        foreach (ListaPrecio::query()->where('activo', true)->orderBy('id')->get(['id', 'nombre']) as $lista) {
            $campos["precio_lista_{$lista->id}"] = [
                'etiqueta' => "Lista de Precios: {$lista->nombre}",
                'obligatorio' => false,
                'numerico' => true,
                'lista_precio_id' => $lista->id,
                // Alias para el auto-mapeo (research.md): el CSV suele traer la columna
                // con el nombre de la lista pelado ("AHORA 12"), no con el prefijo
                // "Lista de Precios: " de la etiqueta mostrada en el select.
                'alias' => $lista->nombre,
            ];
        }

        // Una entrada de mapeo por cada depósito activo: stock inicial de ese
        // producto en ese depósito puntual (genera el mismo movimiento "Registro
        // inicial" que el alta manual — ver StockService::ajustar()).
        foreach (Deposito::query()->where('activo', true)->orderBy('id')->get(['id', 'nombre']) as $deposito) {
            $campos["stock_deposito_{$deposito->id}"] = [
                'etiqueta' => "Stock: {$deposito->nombre}",
                'obligatorio' => false,
                'numerico' => true,
                'deposito_id' => $deposito->id,
                // Dos alias: el nombre pelado del depósito ("Local") y el encabezado tal cual lo
                // escribe la exportación de Productos ("Stock Local"). Sin el segundo, exportar →
                // editar → reimportar dejaba estas columnas sin mapear y el stock no se
                // actualizaba salvo que el usuario las mapeara a mano (spec 074).
                'alias' => [$deposito->nombre, "Stock {$deposito->nombre}"],
            ];
        }

        // "Stock Total" no se persiste (no hay un depósito único al que atribuirlo
        // sin duplicar el conteo real de stock, que ya es la suma de todos los
        // depósitos — Producto::stockTotal()): sólo sirve como chequeo cruzado
        // contra la suma de los "Stock: {depósito}" mapeados en la misma fila.
        $campos['stock_total_verificacion'] = [
            'etiqueta' => 'Stock Total (sólo verificación, no se guarda)',
            'obligatorio' => false,
            'numerico' => true,
            'solo_verificacion' => true,
            'alias' => 'Stock Total',
        ];

        return $campos;
    }

    /**
     * @return array<string, array{etiqueta: string, obligatorio: bool, fk?: array{modelo: class-string, scope?: string}, default?: mixed}>
     */
    public static function paraEntidad(string $entidad): array
    {
        return match ($entidad) {
            'clientes' => self::clientes(),
            'proveedores' => self::proveedores(),
            'productos' => self::productos(),
        };
    }
}
