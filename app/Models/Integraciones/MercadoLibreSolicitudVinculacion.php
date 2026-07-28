<?php

namespace App\Models\Integraciones;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Protección del retorno de autorización OAuth (parámetro `state`) — ver research.md R6.
 */
class MercadoLibreSolicitudVinculacion extends Model
{
    protected $table = 'ml_solicitudes_vinculacion';

    protected $fillable = [
        'state', 'estado', 'expira_en', 'consumida_en', 'iniciada_por', 'ip',
    ];

    protected $casts = [
        'expira_en' => 'datetime',
        'consumida_en' => 'datetime',
    ];

    public function iniciadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciada_por');
    }

    public static function emitir(User $usuario, string $ip): self
    {
        static::query()
            ->where('estado', 'pendiente')
            ->where('expira_en', '<', Carbon::now())
            ->delete();

        return static::create([
            'state' => Str::random(40),
            'estado' => 'pendiente',
            'expira_en' => Carbon::now()->addMinutes(10),
            'iniciada_por' => $usuario->id,
            'ip' => $ip,
        ]);
    }

    public function consumir(): void
    {
        $this->update([
            'estado' => 'consumida',
            'consumida_en' => Carbon::now(),
        ]);
    }
}
