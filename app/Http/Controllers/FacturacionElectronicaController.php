<?php

namespace App\Http\Controllers;

use App\Models\CertificadoFiscal;
use App\Models\PuntoVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuración & Ajustes → Facturación Electrónica (ARCA/AFIP): certificado y Puntos de Venta. */
class FacturacionElectronicaController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-facturacion-electronica';
        $certificado = CertificadoFiscal::activo();
        $puntosVenta = PuntoVenta::orderBy('numero')->get();

        return view('configuracion.facturacion-electronica.index', compact('CurrentPage', 'certificado', 'puntosVenta'));
    }

    public function guardarCertificado(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cuit' => ['required', 'string', 'regex:/^\d{2}-?\d{8}-?\d{1}$/'],
            'ambiente' => ['required', 'in:homologacion,produccion'],
            'certificado' => ['required', 'file', 'max:2048'],
            'clave_privada' => ['required', 'file', 'max:2048'],
            'fecha_emision' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
        ]);

        if ($datos['ambiente'] === 'produccion' && ! empty($datos['fecha_vencimiento']) && \Carbon\Carbon::parse($datos['fecha_vencimiento'])->isPast()) {
            return response()->json([
                'ok' => false,
                'message' => 'El certificado cargado ya está vencido — no se puede guardar en ambiente de producción.',
            ], 422);
        }

        $timestamp = now()->timestamp;
        $rutaCert = CertificadoFiscal::guardarArchivoCifrado($request->file('certificado')->get(), "cert_{$timestamp}.crt");
        $rutaClave = CertificadoFiscal::guardarArchivoCifrado($request->file('clave_privada')->get(), "clave_{$timestamp}.key");

        CertificadoFiscal::query()->update(['activo' => false]);

        $certificado = CertificadoFiscal::create([
            'cuit' => preg_replace('/\D/', '', $datos['cuit']),
            'ambiente' => $datos['ambiente'],
            'ruta_certificado' => $rutaCert,
            'ruta_clave_privada' => $rutaClave,
            'fecha_emision' => $datos['fecha_emision'] ?? null,
            'fecha_vencimiento' => $datos['fecha_vencimiento'] ?? null,
            'activo' => true,
        ]);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Certificado fiscal guardado con éxito.',
            'certificado' => $certificado,
        ], 201);
    }

    public function guardarPuntoVenta(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'numero' => ['required', 'integer', 'min:1', 'unique:puntos_venta,numero'],
            'descripcion' => ['required', 'string', 'max:255'],
            'por_defecto' => ['sometimes', 'boolean'],
        ]);

        $puntoVenta = \Illuminate\Support\Facades\DB::transaction(function () use ($datos) {
            if (! empty($datos['por_defecto'])) {
                PuntoVenta::query()->update(['por_defecto' => false]);
            }

            return PuntoVenta::create([
                'numero' => $datos['numero'],
                'descripcion' => $datos['descripcion'],
                'tipo_ws' => 'WS',
                'por_defecto' => (bool) ($datos['por_defecto'] ?? false),
                'activo' => true,
            ]);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Punto de Venta guardado con éxito.',
            'punto_venta' => $puntoVenta,
        ], 201);
    }

    public function puntoVentaEstado(Request $request, PuntoVenta $puntoVenta): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);
        $puntoVenta->update(['activo' => $datos['activo']]);

        return response()->json(['ok' => true, 'mensaje' => 'Punto de Venta actualizado.']);
    }
}
