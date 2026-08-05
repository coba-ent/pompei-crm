@php
    $badge = match ($compra->estadoPago()) {
        'pagado' => 'success',
        'parcial' => 'warning',
        default => 'danger',
    };
    $label = match ($compra->estadoPago()) {
        'pagado' => 'Pagado',
        'parcial' => 'Parcial',
        default => 'A Pagar',
    };
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-{{ $badge }} dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        {{ $label }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('compras.show', $compra) }}">Ver</a></li>
        <li><a class="dropdown-item" href="{{ route('compras.edit', $compra) }}">Editar</a></li>
        <li><a class="dropdown-item" href="{{ route('compras.show', $compra) }}">Ver Detalle</a></li>
        <li><a class="dropdown-item js-agregar-pago" href="{{ route('compras.show', $compra) }}">Agregar Pago</a></li>
        <li><a class="dropdown-item" href="{{ route('compras.show', $compra) }}#notas">Crear NC/ND</a></li>
        <li><a class="dropdown-item" href="{{ route('compras.show', $compra) }}">Crear Remito</a></li>
        <li><a class="dropdown-item disabled" href="#" title="Próximamente">Cta Cte</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item js-imprimir" href="#" data-id="{{ $compra->id }}">Imprimir Detalle</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger js-eliminar" href="#" data-id="{{ $compra->id }}">Eliminar</a></li>
    </ul>
</div>
