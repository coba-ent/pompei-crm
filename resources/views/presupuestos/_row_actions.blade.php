@php
    $badge = match ($presupuesto->estado_visual) {
        'aceptado' => 'success',
        'rechazado' => 'danger',
        'vencido' => 'dark',
        default => 'warning',
    };
    $label = match ($presupuesto->estado_visual) {
        'aceptado' => 'Aceptado',
        'rechazado' => 'Rechazado',
        'vencido' => 'Vencido',
        default => 'Pendiente',
    };
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-{{ $badge }} dropdown-toggle js-fila-estado" type="button"
            data-bs-toggle="dropdown" data-bs-display="static" data-id="{{ $presupuesto->id }}">
        {{ $label }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('presupuestos.show', $presupuesto) }}">Ver</a></li>
        @if (! $presupuesto->convertido())
            <li><a class="dropdown-item" href="{{ route('presupuestos.edit', $presupuesto) }}">Editar</a></li>
            <li><a class="dropdown-item text-danger js-eliminar" href="#" data-id="{{ $presupuesto->id }}">Eliminar</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item js-cambiar-estado" href="#" data-id="{{ $presupuesto->id }}" data-estado="pendiente">Pendiente</a></li>
            <li><a class="dropdown-item js-cambiar-estado" href="#" data-id="{{ $presupuesto->id }}" data-estado="rechazado">Rechazado</a></li>
            <li><a class="dropdown-item js-cambiar-estado" href="#" data-id="{{ $presupuesto->id }}" data-estado="aceptado">Aceptado</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item js-crear-venta" href="#" data-id="{{ $presupuesto->id }}">Crear Venta</a></li>
        @endif
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item js-imprimir" href="#" data-id="{{ $presupuesto->id }}">Ver Presupuesto</a></li>
        <li><a class="dropdown-item js-imprimir" href="#" data-id="{{ $presupuesto->id }}">Imprimir Presupuesto</a></li>
    </ul>
</div>
