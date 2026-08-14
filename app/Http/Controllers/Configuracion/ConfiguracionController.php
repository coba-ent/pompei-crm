<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\CertificadoFiscal;
use App\Models\ConfiguracionVentas;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\ListaPrecio;
use App\Models\PuntoVenta;
use App\Models\Vendedor;

/** Configuración & Ajustes (spec 043): pantalla única con tabs (Funciones Avanzadas, Depósitos, Mercado Libre, Tiendanube, Facturación Electrónica, Ventas). */
class ConfiguracionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-index';

        $funciones = FuncionAvanzada::ordenadas()->get();

        $depositos = Deposito::activos()->orderBy('nombre')->get();
        $categoriasVenta = Categoria::venta()->activas()->orderBy('nombre')->get();
        $listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $vendedores = Vendedor::orderBy('nombre')->get();

        $depositoPorDefecto = Deposito::porDefecto();

        // Tab Mercado Libre
        $depositoEfectivoMl = MercadoLibreConfiguracion::actual()->depositoEfectivoONulo();
        // spec 065/FR-026: mismos datos que la pantalla dedicada, porque el selector de
        // depósito Full está duplicado en las dos vistas y tienen que decir lo mismo.
        $depositoFullEfectivo = MercadoLibreConfiguracion::actual()->depositoFullEfectivoONulo();
        $publicacionesFull = \App\Models\Integraciones\MercadoLibrePublicacionProducto::soloFull()->count();

        // Tab Tiendanube
        $cuentasTesoreria = CuentaTesoreria::visibles()->orderBy('nombre')->get();
        $depositoEfectivoTn = TiendanubeConexionRest::actual()->depositoEfectivoONulo();

        // Tab Facturación Electrónica
        $certificado = CertificadoFiscal::activo();
        $puntosVenta = PuntoVenta::orderBy('numero')->get();

        // Tab Ventas (incluye defaults de Presupuesto y Compra)
        $configuracionVentas = ConfiguracionVentas::first();
        $categoriasCompra = Categoria::compra()->activas()->orderBy('nombre')->get();

        return view('configuracion.index', compact(
            'CurrentPage',
            'funciones',
            'depositos',
            'categoriasVenta',
            'categoriasCompra',
            'listasPrecio',
            'vendedores',
            'depositoPorDefecto',
            'depositoEfectivoMl',
            'depositoFullEfectivo',
            'publicacionesFull',
            'cuentasTesoreria',
            'depositoEfectivoTn',
            'certificado',
            'puntosVenta',
            'configuracionVentas',
        ));
    }
}
