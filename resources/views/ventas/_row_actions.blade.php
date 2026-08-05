@php
    $badge = match ($venta->estadoCobro()) {
        'cobrada' => 'success',
        'parcial' => 'warning',
        default => 'danger',
    };
    $label = match ($venta->estadoCobro()) {
        'cobrada' => 'Cobrada',
        'parcial' => 'Parcial',
        default => 'Sin Cobrar',
    };
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-{{ $badge }} dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        {{ $label }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('ventas.show', $venta) }}">Ver</a></li>
        <li><a class="dropdown-item" href="{{ route('ventas.edit', $venta) }}">Editar</a></li>
        <li><a class="dropdown-item text-danger js-eliminar" href="#" data-id="{{ $venta->id }}">Eliminar</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item js-agregar-cobranza" href="{{ route('ventas.show', $venta) }}">Agregar Cobranza</a></li>
        <li><a class="dropdown-item" href="{{ route('ventas.show', $venta) }}#notas">Crear NC/ND</a></li>
        <li><a class="dropdown-item" href="{{ route('ventas.show', $venta) }}#remitos">Crear Remito</a></li>
        <li><a class="dropdown-item disabled" href="#" title="Próximamente">Cta Cte</a></li>
        @if ($venta->puedeEnviarseAArca())
            <li><a class="dropdown-item js-enviar-arca" href="#" data-id="{{ $venta->id }}" data-url="{{ route('ventas.enviarArca', $venta) }}">Enviar a ARCA</a></li>
        @endif
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item js-imprimir" href="#" data-id="{{ $venta->id }}">Ver Detalle</a></li>
        <li><a class="dropdown-item js-imprimir" href="#" data-id="{{ $venta->id }}">Imprimir Detalle</a></li>
        <li><a class="dropdown-item js-imprimir-ticket" href="#" data-id="{{ $venta->id }}">Imprimir Ticket</a></li>
        <li><a class="dropdown-item disabled" href="#" title="Próximamente">Enviar Detalle</a></li>
        <li><a class="dropdown-item disabled" href="#" title="Requiere integración de WhatsApp Business">Enviar Whatsapp</a></li>
        <li><a class="dropdown-item disabled" href="#" title="Próximamente">Analizar</a></li>
    </ul>
</div>
