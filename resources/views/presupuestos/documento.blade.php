@extends('layouts.default')

@php
    $ivaTotal = $presupuesto->items->sum(fn ($i) => (float) $i->subtotal_con_iva - (float) $i->subtotal);
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col-sm-6">
                <a href="{{ route('presupuestos.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Volver
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="text-muted"><i class="fas fa-image fa-2x"></i><div>Agregar Mi Logo</div></div>
                    <div class="text-end">
                        <h4 class="mb-0">PRESUPUESTO {{ $presupuesto->nro_presupuesto }}</h4>
                        <div>Fecha de Emisión: {{ optional($presupuesto->fecha_emision)->format('d/m/Y') }}</div>
                    </div>
                </div>

                @if ($presupuesto->fecha_validez)
                    <div class="border rounded p-2 mb-3 fw-bold">
                        Fecha de Vto. del Presupuesto: {{ $presupuesto->fecha_validez->format('d/m/Y') }}
                    </div>
                @endif

                <div class="border rounded p-3 mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <div><strong>Cliente:</strong> {{ optional($presupuesto->cliente)->nombre }}</div>
                            <div><strong>Nombre:</strong> {{ optional($presupuesto->cliente)->nombre_pila ?: '-' }}</div>
                            <div><strong>Apellido:</strong> {{ optional($presupuesto->cliente)->apellido ?: '-' }}</div>
                            <div><strong>Teléfono:</strong> {{ optional($presupuesto->cliente)->telefono ?: '-' }}</div>
                            <div><strong>Domicilio:</strong> {{ optional($presupuesto->cliente)->domicilio ?: '-' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>CUIT:</strong> {{ optional($presupuesto->cliente)->cuit ?: '-' }}</div>
                            <div><strong>Condición IVA:</strong> {{ optional(optional($presupuesto->cliente)->condicionIva)->nombre ?: '-' }}</div>
                            <div><strong>Categoría:</strong> {{ optional($presupuesto->categoria)->nombre ?: '-' }}</div>
                            <div><strong>Vendedor:</strong> {{ optional($presupuesto->vendedor)->nombre ?: '-' }}</div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th>Cant.</th>
                                <th>Precio Unitario</th>
                                <th>Bonif.</th>
                                <th>Subtotal</th>
                                <th>Alícuota IVA</th>
                                <th>Subtotal c/IVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($presupuesto->items as $item)
                                <tr>
                                    <td>{{ optional($item->producto)->codigo }}</td>
                                    <td>{{ $item->descripcion }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $item->cantidad, 3, ',', '.'), '0'), ',') }}</td>
                                    <td>$ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }}</td>
                                    <td>{{ $item->descuento_pct ? $item->descuento_pct.'%' : '-' }}</td>
                                    <td>$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                                    <td>{{ \App\Models\Producto::etiquetaIva($item->iva_pct) }}</td>
                                    <td>$ {{ number_format((float) $item->subtotal_con_iva, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($presupuesto->conceptos->isNotEmpty())
                    <div class="table-responsive mb-4">
                        <table class="table table-sm">
                            @foreach ($presupuesto->conceptos as $concepto)
                                <tr>
                                    <td>{{ $concepto->concepto }}</td>
                                    <td class="text-end">$ {{ number_format((float) $concepto->monto, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif

                <div class="row justify-content-end mb-4">
                    <div class="col-md-4">
                        <table class="table table-sm">
                            <tr><td>Importe Neto Gravado</td><td class="text-end">$ {{ number_format((float) $presupuesto->subtotal_con_descuento, 2, ',', '.') }}</td></tr>
                            <tr><td>IVA</td><td class="text-end">$ {{ number_format($ivaTotal, 2, ',', '.') }}</td></tr>
                            <tr class="fw-bold"><td>Total Presupuesto</td><td class="text-end">$ {{ number_format((float) $presupuesto->total, 2, ',', '.') }}</td></tr>
                        </table>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6"><strong>Formas de Pago:</strong> {{ $presupuesto->formas_pago ?: '-' }}</div>
                    <div class="col-md-6"><strong>Métodos de Envío:</strong> {{ $presupuesto->metodos_envio ?: '-' }}</div>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="button" class="btn btn-outline-secondary disabled" title="Próximamente">Enviar Presupuesto</button>
                <button type="button" class="btn btn-outline-primary" id="btn-imprimir" data-url="{{ route('presupuestos.pdf', $presupuesto) }}">Imprimir Presupuesto</button>
                <button type="button" class="btn btn-outline-primary" id="btn-exportar" data-url="{{ route('presupuestos.pdf', $presupuesto) }}">Exportar Presupuesto</button>
                @if (! $presupuesto->convertido())
                    <a href="{{ route('presupuestos.edit', $presupuesto) }}" class="btn btn-primary">Editar</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    document.getElementById('btn-imprimir')?.addEventListener('click', function () {
        if (window.AppPdf) {
            window.AppPdf.abrir(this.dataset.url, 'Presupuesto');
        } else {
            window.open(this.dataset.url, '_blank');
        }
    });
    document.getElementById('btn-exportar')?.addEventListener('click', function () {
        if (window.AppPdf) {
            window.AppPdf.abrir(this.dataset.url, 'Presupuesto');
        } else {
            window.open(this.dataset.url, '_blank');
        }
    });
</script>
@endsection
