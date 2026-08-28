<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarInformacionContador;
use App\Models\EnvioContador;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use App\Services\Informes\Contador\ValidadorDestinatarios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Enviar Información a tu Contador por Correo" (spec 087): modal sobre el informe de la spec 077.
 * Delgado a propósito, igual que {@see InformeContadorController} — toda la decisión de qué
 * corresponde vive en {@see PaqueteContador}.
 */
class EnvioContadorController extends Controller
{
    public function __construct(private PaqueteContador $paquete) {}

    /** T023: alimenta el panel de adjuntos en vivo — sólo la lista, sin generar nada (FR-008). */
    public function adjuntosPrevistos(Request $request): JsonResponse
    {
        $periodo = $this->periodoDesde($request);
        $opciones = $this->opcionesValidas($request);

        return response()->json(['archivos' => $opciones === null ? [] : $this->paquete->listar($periodo, $opciones)]);
    }

    /** T024/T021: valida, registra el envío como `pendiente` (protección de doble clic) y encola. */
    public function enviar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2000'],
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'destinatarios' => ['required', 'string'],
            'copia_remitente' => ['boolean'],
            'incluye_electronicas' => ['boolean'],
            'incluye_manuales' => ['boolean'],
            'incluye_pdfs' => ['boolean'],
            'asunto' => ['required', 'string', 'max:255'],
            'cuerpo' => ['required', 'string'],
            'adjuntos_propios' => ['array'],
            'adjuntos_propios.*' => ['file', 'max:20480'],
        ]);

        try {
            $direcciones = (new ValidadorDestinatarios)->parsear($datos['destinatarios']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $periodo = new Periodo($datos['anio'], $datos['mes'] ?? null);

        try {
            $opciones = new OpcionesEnvio(
                incluyeElectronicas: (bool) ($datos['incluye_electronicas'] ?? false),
                incluyeManuales: (bool) ($datos['incluye_manuales'] ?? false),
                incluyePdfs: (bool) ($datos['incluye_pdfs'] ?? false),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $nombresPrevistos = $this->paquete->listar($periodo, $opciones);

        // FR-023: protección de doble clic también del lado del servidor — un envío `pendiente` o
        // `enviado` para el mismo usuario+período+opciones en el último minuto bloquea el reintento.
        $duplicado = EnvioContador::where('user_id', Auth::id())
            ->where('anio', $periodo->anio)
            ->where('mes', $periodo->mes)
            ->where('incluye_electronicas', $opciones->incluyeElectronicas)
            ->where('incluye_manuales', $opciones->incluyeManuales)
            ->where('incluye_pdfs', $opciones->incluyePdfs)
            ->whereIn('estado', ['pendiente', 'enviado'])
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($duplicado) {
            return response()->json(['message' => 'Ya se está procesando (o ya se envió) este mismo período. Esperá un momento antes de reintentar.'], 409);
        }

        $adjuntosPropios = [];
        foreach ($request->file('adjuntos_propios', []) as $archivo) {
            $ruta = $archivo->store('adjuntos-contador-tmp');
            $adjuntosPropios[$archivo->getClientOriginalName()] = storage_path('app/'.$ruta);
        }

        // FR-022/SC-006: el tamaño de los adjuntos generados (XLSX, ZIPs) sólo se conoce una vez
        // generados, así que la verificación completa sucede dentro del job, antes de enviar — acá
        // ya se sabe si hay adjuntos propios pesados, pero repetir el chequeo evitaría generar el
        // paquete completo dos veces. Se deja un único punto de verificación en el job.

        $envio = DB::transaction(function () use ($periodo, $opciones, $direcciones, $datos, $nombresPrevistos) {
            return EnvioContador::create([
                'user_id' => Auth::id(),
                'destinatarios' => implode(', ', $direcciones),
                'copia_remitente' => (bool) ($datos['copia_remitente'] ?? false),
                'anio' => $periodo->anio,
                'mes' => $periodo->mes,
                'incluye_electronicas' => $opciones->incluyeElectronicas,
                'incluye_manuales' => $opciones->incluyeManuales,
                'incluye_pdfs' => $opciones->incluyePdfs,
                'archivos' => $nombresPrevistos,
                'asunto' => $datos['asunto'],
                'estado' => 'pendiente',
            ]);
        });

        EnviarInformacionContador::dispatch(
            $envio->id,
            $periodo,
            $opciones,
            $direcciones,
            (bool) ($datos['copia_remitente'] ?? false),
            Auth::user()->email,
            $datos['asunto'],
            $datos['cuerpo'],
            $adjuntosPropios,
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'El envío se está procesando. Te avisamos cuando termine.',
        ]);
    }

    private function periodoDesde(Request $request): ?Periodo
    {
        if (! $request->filled('anio')) {
            return null;
        }

        return new Periodo((int) $request->input('anio'), $request->filled('mes') ? (int) $request->input('mes') : null);
    }

    private function opcionesValidas(Request $request): ?OpcionesEnvio
    {
        try {
            return new OpcionesEnvio(
                incluyeElectronicas: filter_var($request->input('incluye_electronicas', true), FILTER_VALIDATE_BOOLEAN),
                incluyeManuales: filter_var($request->input('incluye_manuales', false), FILTER_VALIDATE_BOOLEAN),
                incluyePdfs: filter_var($request->input('incluye_pdfs', false), FILTER_VALIDATE_BOOLEAN),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
