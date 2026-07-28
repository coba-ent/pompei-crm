<?php

namespace App\Models\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MercadoLibreCuenta extends Model
{
    protected $table = 'ml_cuentas';

    protected $fillable = [
        'ml_user_id', 'nickname', 'email', 'tipo_cuenta', 'site_id',
        'access_token', 'refresh_token', 'token_expira_en', 'estado',
        'pendiente_expira_en', 'vinculada_en', 'ultimo_refresh_en',
        'ultimo_error', 'vinculada_por',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expira_en' => 'datetime',
        'pendiente_expira_en' => 'datetime',
        'vinculada_en' => 'datetime',
        'ultimo_refresh_en' => 'datetime',
        'estado' => EstadoConexion::class,
    ];

    public function vinculadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vinculada_por');
    }

    /** ¿El access token está vencido o a menos de $margenMinutos de vencer? */
    public function tokenVencido(int $margenMinutos = 10): bool
    {
        if (! $this->token_expira_en) {
            return true;
        }

        return now()->addMinutes($margenMinutos)->greaterThanOrEqualTo($this->token_expira_en);
    }

    public function scopeConectada(Builder $query): Builder
    {
        return $query->where('estado', EstadoConexion::Conectada->value);
    }

    public function scopePendienteConfirmacion(Builder $query): Builder
    {
        return $query->where('estado', EstadoConexion::PendienteConfirmacion->value);
    }

    /**
     * Elimina las autorizaciones retenidas (pendiente_confirmacion) cuya ventana
     * de confirmación ya venció, junto con sus tokens (FR-022, data-model.md §3).
     */
    public static function descartarPendientesVencidas(): void
    {
        static::pendienteConfirmacion()
            ->where('pendiente_expira_en', '<', Carbon::now())
            ->delete();
    }
}
