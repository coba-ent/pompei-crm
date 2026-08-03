@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Facturación Electrónica (ARCA/AFIP)</h4>
                <p class="text-muted mb-0">
                    Certificado fiscal y Puntos de Venta para emitir comprobantes con CAE real.
                </p>
            </div>
        </div>

        @if ($certificado && $certificado->vencido())
            <div class="alert alert-danger">
                <strong>Certificado vencido</strong> desde {{ $certificado->fecha_vencimiento->format('d/m/Y') }} —
                las emisiones van a usar el fallback local sin validez fiscal hasta cargar uno nuevo.
            </div>
        @elseif ($certificado && $certificado->proximoAVencer())
            <div class="alert alert-warning">
                <strong>Certificado próximo a vencer</strong>: {{ $certificado->fecha_vencimiento->format('d/m/Y') }}.
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Certificado fiscal</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-certificado-arca">
                    <i class="fas fa-pencil-alt me-1"></i> Cargar certificado
                </button>
            </div>
            <div class="card-body">
                @if ($certificado)
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label text-muted mb-1">CUIT</label><div class="fw-bold">{{ $certificado->cuit }}</div></div>
                        <div class="col-md-3"><label class="form-label text-muted mb-1">Ambiente</label><div class="fw-bold">{{ ucfirst($certificado->ambiente) }}</div></div>
                        <div class="col-md-3"><label class="form-label text-muted mb-1">Emisión</label><div class="fw-bold">{{ optional($certificado->fecha_emision)->format('d/m/Y') ?: '-' }}</div></div>
                        <div class="col-md-3"><label class="form-label text-muted mb-1">Vencimiento</label><div class="fw-bold">{{ optional($certificado->fecha_vencimiento)->format('d/m/Y') ?: '-' }}</div></div>
                    </div>
                @else
                    <p class="text-muted mb-0">No hay certificado fiscal cargado — las Ventas usan numeración local sin validez fiscal.</p>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Puntos de Venta</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-punto-venta">
                    <i class="fas fa-plus me-1"></i> Nuevo Punto de Venta
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0" id="tabla-puntos-venta">
                    <thead>
                        <tr><th>Número</th><th>Descripción</th><th>Por defecto</th><th>Activo</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($puntosVenta as $pv)
                            <tr>
                                <td>{{ str_pad($pv->numero, 4, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $pv->descripcion }}</td>
                                <td>{{ $pv->por_defecto ? 'Sí' : 'No' }}</td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input js-punto-venta-estado" data-id="{{ $pv->id }}" {{ $pv->activo ? 'checked' : '' }}>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">Sin Puntos de Venta cargados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

{{-- Modal: Certificado fiscal --}}
<div class="modal fade" id="modal-certificado-arca" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-certificado-arca" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Cargar certificado fiscal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">CUIT</label>
                        <input type="text" class="form-control" name="cuit" placeholder="20-12345678-9" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ambiente</label>
                        <select class="form-select" name="ambiente" required>
                            <option value="homologacion">Homologación</option>
                            <option value="produccion">Producción</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Certificado (.crt)</label>
                        <input type="file" class="form-control" name="certificado" accept=".crt,.pem" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Clave privada (.key)</label>
                        <input type="file" class="form-control" name="clave_privada" accept=".key,.pem" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Fecha de emisión</label>
                            <input type="date" class="form-control" name="fecha_emision">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Fecha de vencimiento</label>
                            <input type="date" class="form-control" name="fecha_vencimiento">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-certificado-arca">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal: Punto de Venta --}}
<div class="modal fade" id="modal-punto-venta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-punto-venta">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Nuevo Punto de Venta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Número (asignado por ARCA)</label>
                        <input type="number" class="form-control" name="numero" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <input type="text" class="form-control" name="descripcion" placeholder="Casa Central" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="por_defecto" id="pv-por-defecto" value="1">
                        <label class="form-check-label" for="pv-por-defecto">Marcar como Punto de Venta por defecto</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

@section('local-js')
<script>
(function () {
    const rutas = {
        certificado: @json(route('configuracion.arca.certificado.guardar')),
        puntoVenta: @json(route('configuracion.arca.puntos-venta.guardar')),
        puntoVentaEstadoBase: @json(url('configuracion/arca/puntos-venta')),
    };

    function toast(tipo, mensaje) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje);
        }
    }

    document.getElementById('form-certificado-arca').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch(rutas.certificado, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
            body: formData,
        })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) { throw data; }
                toast('success', data.mensaje);
                window.location.reload();
            })
            .catch((err) => toast('error', err.message || 'No se pudo guardar el certificado.'));
    });

    document.getElementById('form-punto-venta').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        const payload = Object.fromEntries(formData.entries());
        payload.por_defecto = formData.has('por_defecto');
        fetch(rutas.puntoVenta, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
            },
            body: JSON.stringify(payload),
        })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) { throw data; }
                toast('success', data.mensaje);
                window.location.reload();
            })
            .catch((err) => toast('error', err.message || 'No se pudo guardar el Punto de Venta.'));
    });

    document.querySelectorAll('.js-punto-venta-estado').forEach((input) => {
        input.addEventListener('change', function () {
            fetch(rutas.puntoVentaEstadoBase + '/' + this.dataset.id + '/estado', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({ activo: this.checked }),
            })
                .then((r) => r.json())
                .then((data) => toast('success', data.mensaje))
                .catch(() => toast('error', 'No se pudo actualizar el Punto de Venta.'));
        });
    });
})();
</script>
@endsection
@endsection
