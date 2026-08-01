<?php

namespace App\Models\Integraciones;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historial de la conexión Application REST (spec 022) — copia independiente
 * de TiendanubeOperacionLog (research.md R1), sin heredar ni compartir código,
 * para no crear ningún acoplamiento entre esta conexión y la conexión MCP de
 * spec 019. Nunca contiene access_token ni client_secret.
 */
class TiendanubeRestOperacionLog extends Model
{
    protected $table = 'tn_rest_operaciones_log';

    public $timestamps = false;

    protected $fillable = [
        'operacion', 'metodo', 'endpoint', 'sentido', 'resultado',
        'codigo_http', 'duracion_ms', 'mensaje_error', 'usuario_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    private const CAMPOS_SENSIBLES = ['access_token', 'client_secret', 'Authentication'];

    private const RETENCION_DIAS = 30;

    private const RETENCION_MAXIMO_REGISTROS = 5000;

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public static function registrar(array $datos): self
    {
        $datos = self::sanear($datos);
        $datos['created_at'] = $datos['created_at'] ?? Carbon::now();

        $registro = static::create($datos);

        if (random_int(1, 50) === 1) {
            self::depurarPorRetencion();
        }

        return $registro;
    }

    private static function sanear(array $datos): array
    {
        foreach (self::CAMPOS_SENSIBLES as $campo) {
            unset($datos[$campo]);
        }

        if (isset($datos['mensaje_error']) && is_string($datos['mensaje_error'])) {
            foreach (self::CAMPOS_SENSIBLES as $campo) {
                $datos['mensaje_error'] = preg_replace('/'.preg_quote($campo, '/').'["\']?\s*[:=]\s*\S+/i', $campo.': [omitido]', $datos['mensaje_error']);
            }
        }

        return $datos;
    }

    private static function depurarPorRetencion(): void
    {
        static::query()
            ->where('created_at', '<', Carbon::now()->subDays(self::RETENCION_DIAS))
            ->delete();

        $total = static::query()->count();

        if ($total > self::RETENCION_MAXIMO_REGISTROS) {
            $idsAConservar = static::query()
                ->orderByDesc('created_at')
                ->limit(self::RETENCION_MAXIMO_REGISTROS)
                ->pluck('id');

            static::query()->whereNotIn('id', $idsAConservar)->delete();
        }
    }
}
