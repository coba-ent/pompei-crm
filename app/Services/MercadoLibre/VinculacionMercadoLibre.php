<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibreSolicitudVinculacion;
use App\Models\User;
use App\Services\MercadoLibre\Excepciones\VinculacionRechazadaException;
use App\Services\MercadoLibre\Excepciones\VinculacionYaCompletadaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Flujo OAuth propiamente dicho: armado de la URL de autorización, canje del
 * código, resolución de la cuenta (alta / actualización / reemplazo
 * pendiente — FR-022) y desconexión. No pasa por ClienteMercadoLibre porque
 * corre ANTES de que exista una cuenta autenticada con la que armar un Bearer.
 */
class VinculacionMercadoLibre
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public function urlAutorizacion(User $usuario, string $ip): string
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        if (! $configuracion->estaCompleta()) {
            throw new VinculacionRechazadaException('Cargá primero el App ID y la clave secreta de la aplicación.');
        }

        $dominio = Sitios::dominioAutorizacion($configuracion->site_id);

        if (! $dominio) {
            throw new VinculacionRechazadaException('El sitio configurado no está soportado.');
        }

        $solicitud = MercadoLibreSolicitudVinculacion::emitir($usuario, $ip);

        return $dominio.'/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $configuracion->client_id,
            'redirect_uri' => route('configuracion.mercadolibre.callback'),
            'state' => $solicitud->state,
        ]);
    }

    /** @return array{reemplazo_pendiente: bool, cuenta: MercadoLibreCuenta} */
    public function canjearCodigo(string $code, string $state): array
    {
        $solicitud = MercadoLibreSolicitudVinculacion::where('state', $state)->first();

        if (! $solicitud) {
            $this->log('vincular_cuenta', 'GET', '/configuracion/mercadolibre/callback', 'lectura', 'error', null, null, 'state inexistente en el retorno de autorización — posible incidente de seguridad.');

            throw new VinculacionRechazadaException('El enlace de autorización no es válido. Iniciá la conexión de nuevo.');
        }

        if ($solicitud->estado === 'consumida') {
            throw new VinculacionYaCompletadaException('Esta autorización ya se había completado antes. Tu conexión sigue como estaba.');
        }

        if ($solicitud->expira_en->isPast()) {
            $solicitud->update(['estado' => 'vencida']);

            throw new VinculacionRechazadaException('El enlace de autorización venció. Iniciá la conexión de nuevo.');
        }

        // FR-021: se marca consumida ANTES de canjear, para que un retorno repetido no dispare un segundo canje.
        $solicitud->consumir();

        $configuracion = MercadoLibreConfiguracion::actual();
        $redirectUri = route('configuracion.mercadolibre.callback');

        $tokenData = $this->canjearEnProveedor($configuracion, $code, $redirectUri);
        $datosUsuario = $this->obtenerUsuario($tokenData['access_token']);

        if (($datosUsuario['site_id'] ?? null) !== $configuracion->site_id) {
            throw new VinculacionRechazadaException(
                'La cuenta autorizada pertenece al sitio '.($datosUsuario['site_id'] ?? '?')." y la aplicación está configurada para {$configuracion->site_id}. No se guardó nada."
            );
        }

        return $this->resolverCuenta($tokenData, $datosUsuario, $solicitud->iniciada_por);
    }

    private function canjearEnProveedor(MercadoLibreConfiguracion $configuracion, string $code, string $redirectUri): array
    {
        $inicio = microtime(true);

        try {
            $respuesta = Http::asForm()->timeout(30)->connectTimeout(10)->acceptJson()
                ->post(self::API_BASE.'/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'client_id' => $configuracion->client_id,
                    'client_secret' => $configuracion->client_secret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ]);
        } catch (ConnectionException $e) {
            $this->log('vincular_cuenta', 'POST', '/oauth/token', 'escritura', 'error', null, $this->duracionMs($inicio), 'No se pudo conectar con Mercado Libre.');

            throw new VinculacionRechazadaException('No se pudo conectar con Mercado Libre. Intentá de nuevo.');
        }

        $duracionMs = $this->duracionMs($inicio);

        if (! $respuesta->successful()) {
            $mensaje = $this->traducirErrorCanje($respuesta);
            $this->log('vincular_cuenta', 'POST', '/oauth/token', 'escritura', 'error', $respuesta->status(), $duracionMs, $mensaje);

            throw new VinculacionRechazadaException($mensaje);
        }

        $this->log('vincular_cuenta', 'POST', '/oauth/token', 'escritura', 'exito', 200, $duracionMs);

        return $respuesta->json();
    }

    private function obtenerUsuario(string $accessToken): array
    {
        $inicio = microtime(true);

        try {
            $respuesta = Http::timeout(30)->connectTimeout(10)->withToken($accessToken)->acceptJson()
                ->get(self::API_BASE.'/users/me');
        } catch (ConnectionException $e) {
            $this->log('obtener_usuario', 'GET', '/users/me', 'lectura', 'error', null, $this->duracionMs($inicio), 'No se pudo conectar con Mercado Libre.');

            throw new VinculacionRechazadaException('No se pudieron obtener los datos de la cuenta. Intentá de nuevo.');
        }

        $duracionMs = $this->duracionMs($inicio);

        if (! $respuesta->successful()) {
            $this->log('obtener_usuario', 'GET', '/users/me', 'lectura', 'error', $respuesta->status(), $duracionMs, 'No se pudieron obtener los datos de la cuenta.');

            throw new VinculacionRechazadaException('No se pudieron obtener los datos de la cuenta recién autorizada.');
        }

        $this->log('obtener_usuario', 'GET', '/users/me', 'lectura', 'exito', 200, $duracionMs);

        return $respuesta->json();
    }

    /** @return array{reemplazo_pendiente: bool, cuenta: MercadoLibreCuenta} */
    private function resolverCuenta(array $tokenData, array $datosUsuario, ?int $usuarioId): array
    {
        MercadoLibreCuenta::descartarPendientesVencidas();

        $mlUserId = $datosUsuario['id'];
        $cuentaVigente = MercadoLibreCuenta::conectada()->first();

        $datosComunes = [
            'nickname' => $datosUsuario['nickname'] ?? null,
            'email' => $datosUsuario['email'] ?? null,
            'tipo_cuenta' => $datosUsuario['user_type'] ?? null,
            'site_id' => $datosUsuario['site_id'] ?? null,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'],
            'token_expira_en' => now()->addSeconds((int) $tokenData['expires_in']),
        ];

        // (a) Sin cuenta previa: alta directa como conectada.
        if (! $cuentaVigente) {
            $cuenta = MercadoLibreCuenta::updateOrCreate(
                ['ml_user_id' => $mlUserId],
                array_merge($datosComunes, [
                    'estado' => EstadoConexion::Conectada->value,
                    'vinculada_en' => now(),
                    'ultimo_refresh_en' => now(),
                    'ultimo_error' => null,
                    'vinculada_por' => $usuarioId,
                    'pendiente_expira_en' => null,
                ])
            );

            // La marca de sincronización queda asociada a la cuenta que estaba conectada
            // cuando se generó. Si esta alta reemplaza a una cuenta distinta que ya se
            // había desconectado antes (no sólo la primera vinculación de todas), esa
            // marca queda vieja y hay que arrancar de cero — si no, la ventana
            // incremental salta por encima de órdenes reales de la cuenta nueva.
            $this->reiniciarMarcaDeSincronizacionSiCorresponde($mlUserId);

            return ['reemplazo_pendiente' => false, 'cuenta' => $cuenta];
        }

        // (b) Misma cuenta vigente: actualiza tokens, sin pedir confirmación (no hay reemplazo).
        if ((string) $cuentaVigente->ml_user_id === (string) $mlUserId) {
            $cuentaVigente->update(array_merge($datosComunes, [
                'estado' => EstadoConexion::Conectada->value,
                'ultimo_refresh_en' => now(),
                'ultimo_error' => null,
            ]));

            return ['reemplazo_pendiente' => false, 'cuenta' => $cuentaVigente];
        }

        // (c) Cuenta distinta: queda pendiente_confirmacion; la vigente sigue intacta y operativa (FR-022).
        MercadoLibreCuenta::pendienteConfirmacion()->delete();

        $pendiente = MercadoLibreCuenta::updateOrCreate(
            ['ml_user_id' => $mlUserId],
            array_merge($datosComunes, [
                'estado' => EstadoConexion::PendienteConfirmacion->value,
                'pendiente_expira_en' => now()->addMinutes(15),
                'vinculada_por' => $usuarioId,
            ])
        );

        return ['reemplazo_pendiente' => true, 'cuenta' => $pendiente];
    }

    /** Sustituye la cuenta vigente por la pendiente, en una única transacción (FR-022). */
    public function confirmarReemplazo(): MercadoLibreCuenta
    {
        return DB::transaction(function () {
            $pendiente = MercadoLibreCuenta::pendienteConfirmacion()->first();

            if (! $pendiente) {
                throw new VinculacionRechazadaException('No hay ninguna autorización pendiente de confirmación.');
            }

            if ($pendiente->pendiente_expira_en && $pendiente->pendiente_expira_en->isPast()) {
                $pendiente->delete();

                throw new VinculacionRechazadaException('La autorización expiró. Volvé a conectar la cuenta.');
            }

            $anterior = MercadoLibreCuenta::conectada()->first();

            if ($anterior) {
                $anterior->update([
                    'estado' => EstadoConexion::Desconectada->value,
                    'access_token' => null,
                    'refresh_token' => null,
                    'token_expira_en' => null,
                ]);
            }

            $pendiente->update([
                'estado' => EstadoConexion::Conectada->value,
                'vinculada_en' => now(),
                'ultimo_refresh_en' => now(),
                'pendiente_expira_en' => null,
                'ultimo_error' => null,
            ]);

            // Reemplazo explícito de cuenta: la marca de sincronización de la cuenta
            // anterior no sirve para la nueva (misma razón que en resolverCuenta).
            MercadoLibreConfiguracion::actual()->update([
                'ultima_sync_en' => null,
                'ultima_sync_resultado' => null,
            ]);

            $this->log('vincular_cuenta', 'POST', '(confirmación de reemplazo de cuenta)', 'escritura', 'exito', null, null);

            return $pendiente->fresh();
        });
    }

    /**
     * La marca de sincronización de órdenes (`ultima_sync_en`) es una ventana de
     * tiempo, no un dato por cuenta. Si nunca hubo otra cuenta vinculada, no hay
     * nada que resetear (ya está en null). Si sí la hubo, esa marca quedó fijada
     * mientras esa otra cuenta operaba y no tiene relación con el historial de la
     * cuenta nueva — dejarla como estaba haría que la próxima sincronización
     * arranque su ventana incremental después de órdenes reales de la cuenta
     * recién conectada, salteándolas para siempre en silencio.
     */
    private function reiniciarMarcaDeSincronizacionSiCorresponde(int|string $mlUserIdNuevo): void
    {
        $huboOtraCuentaAntes = MercadoLibreCuenta::where('ml_user_id', '!=', $mlUserIdNuevo)
            ->whereNotNull('vinculada_en')
            ->exists();

        if ($huboOtraCuentaAntes) {
            MercadoLibreConfiguracion::actual()->update([
                'ultima_sync_en' => null,
                'ultima_sync_resultado' => null,
            ]);
        }
    }

    public function descartarPendiente(): void
    {
        MercadoLibreCuenta::pendienteConfirmacion()->delete();
    }

    /** Limpia credenciales; conserva datos de cuenta e historial (FR-027). */
    public function desconectar(): void
    {
        $cuenta = MercadoLibreCuenta::whereIn('estado', [EstadoConexion::Conectada->value, EstadoConexion::Caida->value])->first();

        if (! $cuenta) {
            return;
        }

        $cuenta->update([
            'estado' => EstadoConexion::Desconectada->value,
            'access_token' => null,
            'refresh_token' => null,
            'token_expira_en' => null,
            'ultimo_error' => null,
        ]);
    }

    private function traducirErrorCanje($respuestaHttp): string
    {
        $cuerpo = $respuestaHttp->json() ?? [];
        $error = $cuerpo['error'] ?? null;

        if ($error === 'invalid_grant') {
            $descripcion = strtolower($cuerpo['error_description'] ?? '');

            if (str_contains($descripcion, 'redirect')) {
                return 'La URL de retorno no coincide con la registrada en Mercado Libre. Copiá la que muestra esta pantalla y pegala en el DevCenter.';
            }

            return 'La autorización expiró o ya fue usada. Volvé a intentar la conexión.';
        }

        return match ($error) {
            'invalid_client' => 'El App ID o la clave secreta no son correctos. Revisá las credenciales.',
            'invalid_scope' => 'La aplicación no tiene los permisos necesarios. Verificá los permisos funcionales habilitados en el DevCenter.',
            'invalid_request' => 'Faltó un dato obligatorio en la solicitud de autorización. Volvé a intentar la conexión.',
            'invalid_operator_user_id' => 'Tenés que autorizar con la cuenta principal de Mercado Libre, no con un usuario operador o colaborador.',
            'unauthorized_client' => 'La aplicación no tiene autorización para esa cuenta o para los permisos solicitados. Revisá los permisos funcionales en el DevCenter.',
            'unauthorized_application' => 'La aplicación está bloqueada por Mercado Libre. Contactá al soporte de desarrolladores.',
            default => 'No se pudo completar la conexión con Mercado Libre.',
        };
    }

    private function duracionMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function log(string $operacion, string $metodo, string $endpoint, string $sentido, string $resultado, ?int $codigoHttp, ?int $duracionMs, ?string $mensajeError = null): void
    {
        MercadoLibreOperacionLog::registrar([
            'operacion' => $operacion,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'sentido' => $sentido,
            'resultado' => $resultado,
            'codigo_http' => $codigoHttp,
            'duracion_ms' => $duracionMs,
            'mensaje_error' => $mensajeError,
            'usuario_id' => auth()->id(),
        ]);
    }
}
