<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Constancia de un envío de información al contador por correo (spec 087, FR-024). */
class EnvioContador extends Model
{
    protected $table = 'envios_contador';

    /**
     * Etapas por las que pasa el envío, en orden, con el porcentaje que muestra la barra al
     * ENTRAR en cada una. Viven acá y no en el JS para que el job y la pantalla no puedan
     * divergir: el job publica la clave, la pantalla la traduce con este mismo mapa.
     */
    public const ETAPAS = [
        'informes' => ['rotulo' => 'Generando informes', 'porcentaje' => 10],
        'pdfs' => ['rotulo' => 'Generando PDFs de facturas', 'porcentaje' => 35],
        'verificando' => ['rotulo' => 'Verificando tamaño', 'porcentaje' => 80],
        'correo' => ['rotulo' => 'Enviando correo', 'porcentaje' => 90],
    ];

    protected $fillable = [
        'user_id', 'destinatarios', 'copia_remitente', 'anio', 'mes',
        'incluye_electronicas', 'incluye_manuales', 'incluye_pdfs',
        'archivos', 'asunto', 'estado', 'etapa', 'error', 'enviado_en',
    ];

    protected $casts = [
        'copia_remitente' => 'boolean',
        'incluye_electronicas' => 'boolean',
        'incluye_manuales' => 'boolean',
        'incluye_pdfs' => 'boolean',
        'archivos' => 'array',
        'enviado_en' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Un envío que ya terminó (bien o mal): la pantalla deja de preguntar por él. */
    public function finalizado(): bool
    {
        return in_array($this->estado, ['enviado', 'fallido'], true);
    }

    /**
     * Lo que necesita la pantalla para dibujar el progreso, en un solo lugar.
     *
     * El 100% se reserva para `enviado`: mientras el correo está saliendo la barra queda en 90,
     * porque llegar a 100 y seguir esperando se lee como que se colgó. Un `fallido` conserva el
     * porcentaje de la etapa donde murió, así se ve *en qué* falló.
     *
     * @return array<string, mixed>
     */
    public function progreso(): array
    {
        $etapa = self::ETAPAS[$this->etapa] ?? null;

        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'finalizado' => $this->finalizado(),
            'etapa' => $this->etapa,
            'rotulo' => match ($this->estado) {
                'enviado' => 'Enviado',
                'fallido' => 'Falló el envío',
                default => $etapa['rotulo'] ?? 'En cola',
            },
            'porcentaje' => match ($this->estado) {
                'enviado' => 100,
                default => $etapa['porcentaje'] ?? 5,
            },
            'error' => $this->error,
            'destinatarios' => $this->destinatarios,
            'archivos' => $this->archivos,
            'enviado_en' => $this->enviado_en?->format('d/m/Y H:i'),
        ];
    }
}
