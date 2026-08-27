<x-mail::message>
# Recuperación de contraseña

Hola {{ $nombre }},

Recibimos un pedido para restablecer la contraseña de tu cuenta en {{ config('app.name') }}.

<x-mail::button :url="$url">
Definir nueva contraseña
</x-mail::button>

Este link es válido por 60 minutos y sólo se puede usar una vez.

Si no pediste este cambio, podés ignorar este correo — tu contraseña actual sigue siendo válida.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
