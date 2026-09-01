@php
    // Colores de Contagram (docs/informe_contagram_egresos.md §2.1): "Pagado" en verde y
    // "A Pagar" en amarillo/ámbar. El rojo queda reservado para "Vencido", que es el estado
    // que realmente pide atención — antes "A Pagar" caía en el `default` y salía en rojo.
    $badge = match ($compra->estadoPago()) {
        'pagado' => 'success',
        'parcial' => 'warning',
        'vencido' => 'danger',
        default => 'warning',
    };
    $label = match ($compra->estadoPago()) {
        'pagado' => 'Pagado',
        'parcial' => 'Parcial',
        'vencido' => 'Vencido',
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
        <li><a class="dropdown-item js-agregar-pago" href="#" data-id="{{ $compra->id }}">Agregar Pago</a></li>
        <li><a class="dropdown-item" href="{{ route('compras.show', $compra) }}#notas">Crear NC/ND</a></li>
        <li><a class="dropdown-item" href="{{ route('compras.show', $compra) }}">Crear Remito</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item js-imprimir" href="#" data-id="{{ $compra->id }}">Imprimir Detalle</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger js-eliminar" href="#" data-id="{{ $compra->id }}">Eliminar</a></li>
    </ul>
</div>
