<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        Acciones
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item js-ver-recibo-cobranza" href="#" data-id="{{ $cobro->id }}" data-url="{{ route('ventas.cobranzas.recibo', [$venta, $cobro]) }}">Ver recibo</a></li>
        <li><a class="dropdown-item js-editar-cobro" href="#" data-id="{{ $cobro->id }}">Editar</a></li>
        <li><a class="dropdown-item text-danger js-eliminar-cobro" href="#" data-id="{{ $cobro->id }}">Eliminar</a></li>
    </ul>
</div>
