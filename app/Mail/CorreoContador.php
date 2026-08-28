<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con la información del período al contador (spec 087, FR-016). Usa la configuración SMTP
 * ya existente del sistema (`config/mail.php` / `.env`) — no introduce configuración nueva.
 *
 * Cuerpo en texto plano, no markdown: el usuario edita libremente el texto en el modal (FR-015), así
 * que forzarlo a través de un template de Blade lo reescribiría con formato no pedido.
 *
 * @param  array<string, string>  $adjuntos  [nombre de archivo => ruta local]
 */
class CorreoContador extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $asuntoCorreo,
        public readonly string $cuerpoCorreo,
        public readonly array $adjuntos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asuntoCorreo);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.contador');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (string $ruta, string $nombre) => Attachment::fromPath($ruta)->as($nombre),
            $this->adjuntos,
            array_keys($this->adjuntos)
        );
    }
}
