<?php

namespace Database\Seeders;

use App\Models\FuncionAvanzada;
use Illuminate\Database\Seeder;

/**
 * Siembra las 10 funciones avanzadas relevadas de Contagram, en su orden original
 * (docs/informe_contagram_funciones_avanzadas.md §1). Idempotente: no pisa el
 * estado `activa` que el usuario ya haya elegido en una corrida posterior.
 */
class FuncionAvanzadaSeeder extends Seeder
{
    public function run(): void
    {
        $funciones = [
            [
                'clave' => 'facturacion_electronica',
                'orden' => 1,
                'nombre' => 'Facturación electrónica',
                'descripcion' => 'Emitir comprobantes electrónicos válidos ante ARCA directamente desde tus ventas.',
                'icono' => 'fas fa-file-invoice-dollar',
                'disponible' => true,
                'activa' => false,
                'ruta_configuracion' => 'configuracion.arca.index',
            ],
            [
                'clave' => 'mercadolibre',
                'orden' => 2,
                'nombre' => 'Mercado Libre',
                'descripcion' => 'Conectar tu cuenta de Mercado Libre para operar publicaciones, stock y ventas desde el CRM.',
                'icono' => 'fas fa-store',
                'disponible' => true,
                'activa' => false,
                'ruta_configuracion' => 'configuracion.mercadolibre.index',
            ],
            [
                'clave' => 'tiendanube',
                'orden' => 3,
                'nombre' => 'Tiendanube',
                'descripcion' => 'Sincronizar tu tienda online de Tiendanube con el catálogo y las ventas del CRM.',
                'icono' => 'fas fa-shopping-bag',
                'disponible' => true,
                'activa' => false,
                'ruta_configuracion' => 'configuracion.tiendanube.index',
            ],
            [
                'clave' => 'reportes_email',
                'orden' => 4,
                'nombre' => 'Reportes por email',
                'descripcion' => 'Recibir reportes periódicos de tu negocio por correo electrónico.',
                'icono' => 'fas fa-envelope-open-text',
                'disponible' => false,
                'activa' => false,
                'ruta_configuracion' => null,
            ],
            [
                'clave' => 'abonos',
                'orden' => 5,
                'nombre' => 'Abonos',
                'descripcion' => 'Generar ventas recurrentes automáticas para tus clientes con suscripciones.',
                'icono' => 'fas fa-sync-alt',
                'disponible' => true,
                'activa' => true,
                'ruta_configuracion' => null,
            ],
            [
                'clave' => 'ia',
                'orden' => 6,
                'nombre' => 'IA',
                'descripcion' => 'Activar las funciones de inteligencia artificial del CRM (sugerencia de precios, análisis de ventas).',
                'icono' => 'fas fa-robot',
                'disponible' => false,
                'activa' => false,
                'ruta_configuracion' => null,
            ],
            [
                'clave' => 'retenciones',
                'orden' => 7,
                'nombre' => 'Retenciones',
                'descripcion' => 'Habilitar la carga de retenciones sobre los cobros a tus clientes.',
                'icono' => 'fas fa-receipt',
                'disponible' => true,
                'activa' => true,
                'ruta_configuracion' => null,
            ],
            [
                'clave' => 'depositos',
                'orden' => 9,
                'nombre' => 'Depósitos',
                'descripcion' => 'Administrar múltiples depósitos o almacenes para el control de stock.',
                'icono' => 'fas fa-warehouse',
                'disponible' => true,
                'activa' => true,
                'ruta_configuracion' => 'configuracion.depositos.index',
            ],
            [
                'clave' => 'lector_codigo_barras',
                'orden' => 10,
                'nombre' => 'Lector de código de barras',
                'descripcion' => 'Usar un lector de código de barras físico para buscar y cargar productos.',
                'icono' => 'fas fa-barcode',
                'disponible' => false,
                'activa' => false,
                'ruta_configuracion' => null,
            ],
            [
                'clave' => 'mercadolibre_bot',
                'orden' => 11,
                'nombre' => 'Bot de Mercado Libre',
                'descripcion' => 'Generar sugerencias de respuesta con IA para los mensajes de Mercado Libre.',
                'icono' => 'fas fa-robot',
                'disponible' => true,
                'activa' => false,
                'ruta_configuracion' => 'configuracion.mercadolibre.bot',
            ],
        ];

        foreach ($funciones as $datos) {
            $existente = FuncionAvanzada::where('clave', $datos['clave'])->first();

            if ($existente) {
                unset($datos['activa']);
                $existente->update($datos);

                continue;
            }

            FuncionAvanzada::create($datos);
        }
    }
}
