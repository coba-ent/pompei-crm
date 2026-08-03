<?php

namespace App\Services\MercadoLibre\Bot;

use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreMensaje;
use OpenAI\Contracts\ClientContract;

/**
 * Implementación default de {@see GeneradorDeSugerencias} vía OpenAI GPT-4o-mini
 * (spec 033, R6/R8 de research.md). El límite de 350 caracteres es el que
 * realmente aplica Mercado Libre a los mensajes de post-venta del vendedor
 * (`seller_max_message_length`, confirmado contra la documentación oficial de
 * ML) — se instruye en el prompt para que la mayoría de las respuestas ya
 * vengan dentro del límite; la validación final la hace el Job (T016).
 */
class GeneradorDeSugerenciasOpenAI implements GeneradorDeSugerencias
{
    public function __construct(private readonly ClientContract $cliente) {}

    public function generar(MercadoLibreConversacion $conversacion, MercadoLibreMensaje $mensaje, string $instrucciones): string
    {
        // El timeout de la llamada (R8/R7 de research.md) lo aplica el Job que
        // invoca este método (GenerarSugerenciaMercadoLibre::$timeout), no un
        // parámetro acá — 'timeout' no es un campo válido del payload de OpenAI.
        $respuesta = $this->cliente->chat()->create([
            'model' => 'gpt-4o-mini',
            'temperature' => 0.5,
            'messages' => [
                ['role' => 'system', 'content' => $this->promptSistema($instrucciones)],
                ['role' => 'user', 'content' => $this->contexto($conversacion, $mensaje)],
            ],
        ]);

        return trim($respuesta->choices[0]->message->content ?? '');
    }

    private function promptSistema(string $instrucciones): string
    {
        $tono = trim($instrucciones) !== ''
            ? $instrucciones
            : 'Tono neutro, cordial y profesional, típico de atención al cliente en Argentina.';

        return "Sos un asistente que redacta borradores de respuesta para el vendedor de una cuenta de ".
            "Mercado Libre en Argentina. Un humano va a revisar y editar tu borrador antes de enviarlo — ".
            "nunca se envía tal cual sin aprobación. Escribí en español rioplatense, directo y breve.\n\n".
            "Reglas obligatorias:\n".
            "- Máximo 350 caracteres (límite real de Mercado Libre para mensajes del vendedor). Preferí ".
            "quedarte bien por debajo de ese límite antes que llegar justo.\n".
            "- No inventes datos de stock, precio o envío que no estén en el contexto provisto.\n".
            "- No pidas ni ofrezcas compartir datos de contacto fuera de Mercado Libre.\n".
            "- Devolvé únicamente el texto del mensaje, sin comillas ni explicaciones alrededor.\n\n".
            "Tono/instrucciones del negocio: {$tono}";
    }

    private function contexto(MercadoLibreConversacion $conversacion, MercadoLibreMensaje $mensaje): string
    {
        $historial = $conversacion->mensajes()->orderBy('enviado_en')->get()
            ->map(fn (MercadoLibreMensaje $m) => ($m->origen === 'negocio' ? 'Vendedor' : 'Comprador').': '.$m->texto)
            ->implode("\n");

        $producto = $conversacion->publicacionProducto?->producto?->nombre;
        $orden = $conversacion->orden;
        $envio = $orden?->payload['shipping'] ?? null;

        $partes = [
            "Historial de la conversación:\n{$historial}",
            "Último mensaje del comprador a responder: \"{$mensaje->texto}\"",
        ];

        if ($producto) {
            $partes[] = "Producto vinculado a la publicación: {$producto}";
        }

        if ($envio) {
            $partes[] = 'Datos de envío de la orden (json crudo de Mercado Libre): '.json_encode($envio);
        }

        return implode("\n\n", $partes);
    }
}
