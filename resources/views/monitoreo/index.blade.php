{{--
    Panel de monitoreo interno. Autocontenido a propósito: no extiende `layouts.default`
    ni usa los assets del template. Así no depende de nada del CRM y nada del CRM depende
    de esto — si mañana cambia el layout o el pagelevel, esta pantalla sigue igual.
--}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Monitoreo</title>
<style>
    :root {
        --fondo:#0d1117; --panel:#161b22; --borde:#30363d; --texto:#e6edf3; --tenue:#8b949e;
        --rojo:#f85149; --rojo-bg:#3d1418; --amarillo:#d29922; --amarillo-bg:#3a2d10;
        --verde:#3fb950; --verde-bg:#12261a; --azul:#58a6ff;
    }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--fondo); color:var(--texto);
        font:14px/1.5 ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace; }
    header { position:sticky; top:0; z-index:10; padding:14px 20px; border-bottom:1px solid var(--borde);
        background:var(--panel); display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
    h1 { margin:0; font-size:15px; letter-spacing:.14em; text-transform:uppercase; color:var(--tenue); font-weight:600; }
    .estado { padding:5px 14px; border-radius:999px; font-weight:700; font-size:13px; }
    .estado.ok { background:var(--verde-bg); color:var(--verde); border:1px solid var(--verde); }
    .estado.mal { background:var(--rojo-bg); color:var(--rojo); border:1px solid var(--rojo); }
    .reloj { margin-left:auto; color:var(--tenue); font-size:12px; }
    main { padding:20px; display:grid; gap:18px; grid-template-columns:repeat(auto-fit,minmax(460px,1fr)); }
    section { background:var(--panel); border:1px solid var(--borde); border-radius:10px; overflow:hidden; }
    section.ancho { grid-column:1/-1; }
    .cab { padding:11px 16px; border-bottom:1px solid var(--borde); display:flex; align-items:center; gap:10px; }
    .cab h2 { margin:0; font-size:12px; letter-spacing:.1em; text-transform:uppercase; color:var(--tenue); font-weight:600; }
    .cuenta { margin-left:auto; font-weight:700; font-size:13px; padding:2px 10px; border-radius:999px;
        background:#21262d; color:var(--tenue); }
    .cuenta.rojo { background:var(--rojo-bg); color:var(--rojo); }
    .cuenta.amarillo { background:var(--amarillo-bg); color:var(--amarillo); }
    .cuerpo { max-height:340px; overflow:auto; }
    .fila { padding:11px 16px; border-bottom:1px solid #21262d; display:flex; gap:12px; align-items:flex-start; }
    .fila:last-child { border-bottom:0; }
    .fila .nom { flex:1; min-width:0; }
    .fila .nom b { display:block; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .fila .nom small { color:var(--tenue); font-size:11.5px; }
    .badge { font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:5px; white-space:nowrap;
        letter-spacing:.04em; text-transform:uppercase; }
    .b-rojo { background:var(--rojo-bg); color:var(--rojo); }
    .b-amarillo { background:var(--amarillo-bg); color:var(--amarillo); }
    .b-verde { background:var(--verde-bg); color:var(--verde); }
    .b-gris { background:#21262d; color:var(--tenue); }
    .err { color:var(--rojo); font-size:11.5px; margin-top:3px; word-break:break-word; }
    button { font:inherit; font-size:11.5px; font-weight:600; cursor:pointer; padding:5px 11px;
        border-radius:6px; border:1px solid var(--borde); background:#21262d; color:var(--texto); }
    button:hover { border-color:var(--azul); color:var(--azul); }
    button:disabled { opacity:.4; cursor:default; }
    .tira { display:flex; gap:26px; padding:14px 16px; flex-wrap:wrap; }
    .tira div small { display:block; color:var(--tenue); font-size:10.5px; text-transform:uppercase; letter-spacing:.08em; }
    .tira div b { font-size:14px; }
    .vacio { padding:26px 16px; text-align:center; color:var(--tenue); font-size:12.5px; }
    .aviso { margin:0 20px; padding:10px 14px; border-radius:8px; background:var(--azul); color:#04121f;
        font-weight:700; font-size:12.5px; }
</style>
</head>
<body>

<header>
    <h1>Monitoreo</h1>
    <span id="estado" class="estado ok">cargando…</span>
    <button id="btn-sync" onclick="sincronizar()">Sincronizar stock ahora</button>
    <span class="reloj">servidor <b id="reloj">—</b> · refresca cada 30s</span>
</header>

<div id="aviso" class="aviso" style="display:none"></div>

<main>
    <section>
        <div class="cab"><h2>No puede actualizar en Mercado Libre</h2><span id="c-fallando" class="cuenta">0</span></div>
        <div id="p-fallando" class="cuerpo"></div>
    </section>

    <section>
        <div class="cab"><h2>Quedándose sin stock</h2><span id="c-bajo" class="cuenta">0</span></div>
        <div id="p-bajo" class="cuerpo"></div>
    </section>

    <section>
        <div class="cab"><h2>Sin stock en Local y en Full</h2><span id="c-sin" class="cuenta">0</span></div>
        <div id="p-sin" class="cuerpo"></div>
    </section>

    <section>
        <div class="cab"><h2>Órdenes de ML sin venta creada</h2><span id="c-ordenes" class="cuenta">0</span></div>
        <div id="p-ordenes" class="cuerpo"></div>
    </section>

    <section>
        <div class="cab"><h2>Últimas ventas de integraciones</h2></div>
        <div id="p-ventas" class="cuerpo"></div>
    </section>

    <section class="ancho">
        <div class="cab"><h2>Pulso del sistema</h2></div>
        <div id="p-pulso" class="tira"></div>
    </section>
</main>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const $ = (id) => document.getElementById(id);
const esc = (t) => String(t ?? '').replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const num = (n) => new Intl.NumberFormat('es-AR').format(n);

function avisar(texto) {
    const a = $('aviso');
    a.textContent = texto;
    a.style.display = 'block';
    setTimeout(() => { a.style.display = 'none'; }, 6000);
}

function pintar(d) {
    $('reloj').textContent = d.servidor;

    // --- 1. No puede actualizar ---
    const f = d.fallando;
    $('c-fallando').textContent = f.length;
    $('c-fallando').className = 'cuenta' + (f.some((x) => !x.moderacion) ? ' rojo' : (f.length ? ' amarillo' : ''));
    $('p-fallando').innerHTML = f.length ? f.map((x) => `
        <div class="fila">
            <div class="nom">
                <b>${esc(x.titulo)}</b>
                <small>${esc(x.item)} · producto ${x.productoId} · CRM ${num(x.stock)}${x.publicado !== null ? ' → publicado ' + num(x.publicado) : ''}</small>
                <div class="err">${esc(x.error)}</div>
                <small>${x.intentos} intento(s)${x.desde ? ' · desde ' + esc(x.desde) : ''}</small>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
                <span class="badge ${x.moderacion ? 'b-amarillo' : 'b-rojo'}">${x.moderacion ? 'moderación ML' : 'falla'}</span>
                ${x.bloqueada ? '<span class="badge b-gris">bloqueada</span>' : ''}
                <button onclick="accion('reactivar','${esc(x.item)}')">Reintentar</button>
            </div>
        </div>`).join('') : '<div class="vacio">Todas las publicaciones sincronizando bien.</div>';

    // --- 2. Quedándose sin stock ---
    const b = d.stockBajo;
    $('c-bajo').textContent = b.length;
    $('c-bajo').className = 'cuenta' + (b.some((x) => x.dias !== null && x.dias < 3) ? ' rojo' : (b.length ? ' amarillo' : ''));
    $('p-bajo').innerHTML = b.length ? b.map((x) => {
        const cls = x.dias === null ? 'b-gris' : (x.dias < 3 ? 'b-rojo' : (x.dias < 7 ? 'b-amarillo' : 'b-verde'));
        const txt = x.dias === null ? 'sin rotación' : `${x.dias} días`;
        return `<div class="fila">
            <div class="nom">
                <b>${esc(x.nombre)}</b>
                <small>id ${x.id} · ${num(x.stock)} en depósito · ${x.porDia}/día${x.enMl ? ' · publicado en ML' : ''}</small>
            </div>
            <span class="badge ${cls}">${txt}</span>
        </div>`;
    }).join('') : '<div class="vacio">Ningún producto por debajo de 3 unidades.</div>';

    // --- 3. Sin stock en ambos ---
    const s = d.sinStock;
    $('c-sin').textContent = s.length;
    $('p-sin').innerHTML = s.length ? s.map((x) => `
        <div class="fila">
            <div class="nom">
                <b>${esc(x.nombre)}</b>
                <small>id ${x.id} · ${esc(x.item)} · Local ${num(x.local)} · Full ${num(x.full)}</small>
            </div>
            <span class="badge b-gris">no vende</span>
        </div>`).join('') : '<div class="vacio">Todos los productos publicados tienen stock.</div>';

    // --- 4. Órdenes sin venta ---
    const o = d.ordenesSinVenta;
    const trabadas = o.filter((x) => x.accionable).length;
    $('c-ordenes').textContent = o.length;
    $('c-ordenes').className = 'cuenta' + (trabadas ? ' rojo' : '');
    $('p-ordenes').innerHTML = o.length ? o.map((x) => {
        const cls = x.accionable ? 'b-rojo' : (x.estado === 'cancelada' ? 'b-gris' : 'b-amarillo');
        return `<div class="fila">
            <div class="nom">
                <b>${esc(x.comprador ?? 'sin comprador')} · $ ${num(x.total.toFixed(2))}</b>
                <small>orden ${esc(x.orden)}${x.cuando ? ' · ' + esc(x.cuando) : ''}</small>
                <div class="${x.accionable ? 'err' : ''}" style="font-size:11.5px;margin-top:3px">${esc(x.causa)}</div>
                ${x.detalle ? `<small>${esc(x.detalle)}</small>` : ''}
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end">
                <span class="badge ${cls}">${esc(x.estado.replace('_', ' '))}</span>
                ${x.mediacion ? '<span class="badge b-amarillo">mediación</span>' : ''}
                ${x.fraude ? '<span class="badge b-rojo">fraude</span>' : ''}
            </div>
        </div>`;
    }).join('') : '<div class="vacio">Todas las órdenes se convirtieron en venta.</div>';

    // --- 5. Últimas ventas ---
    $('p-ventas').innerHTML = d.ultimasVentas.map((v) => {
        const bien = v.movimientos > 0 && v.neto < 0;
        return `<div class="fila">
            <div class="nom">
                <b>Venta ${v.id} · $ ${num(v.total.toFixed(2))}</b>
                <small>${esc(v.origen)} · ${esc(v.cuando)} · depósito ${esc(v.deposito)}</small>
            </div>
            <span class="badge ${bien ? 'b-verde' : 'b-rojo'}">${bien ? 'descontó ' + num(-v.neto) : 'sin descontar'}</span>
        </div>`;
    }).join('');

    // --- Pulso ---
    const p = d.sincronizacion;
    const hace = (m) => m === null ? 'nunca' : (m === 0 ? 'recién' : `hace ${m} min`);
    $('p-pulso').innerHTML = `
        <div><small>Órdenes</small><b class="${p.ordenes.alerta ? 'err' : ''}">${hace(p.ordenes.hace)}</b>
             <small style="text-transform:none;letter-spacing:0">${esc(p.ordenes.resultado ?? '—')}</small></div>
        <div><small>Stock</small><b class="${p.stock.alerta ? 'err' : ''}">${hace(p.stock.hace)}</b>
             <small style="text-transform:none;letter-spacing:0">${esc(p.stock.resultado ?? '—')}</small></div>
        <div><small>Último movimiento</small><b>${esc(p.ultimoMovimiento ?? '—')}</b></div>
        <div><small>Publicaciones</small><b>${p.publicaciones}</b></div>
        <div><small>Órdenes sin venta</small><b>${p.ordenesSinVenta}</b></div>
        <div><small>Sólo lectura</small><b class="${p.soloLectura ? 'err' : ''}">${p.soloLectura ? 'SÍ' : 'no'}</b></div>
        <div><small>Creación automática</small><b>${p.creacionAutomatica ? 'sí' : 'NO'}</b></div>`;

    // --- Semáforo general: sólo lo que es falla nuestra ---
    const problemas = f.filter((x) => !x.moderacion).length
        + b.filter((x) => x.dias !== null && x.dias < 3).length
        + trabadas
        + (p.ordenes.alerta ? 1 : 0) + (p.stock.alerta ? 1 : 0);
    $('estado').textContent = problemas ? `${problemas} alerta${problemas > 1 ? 's' : ''}` : 'todo en orden';
    $('estado').className = 'estado ' + (problemas ? 'mal' : 'ok');
    document.title = (problemas ? `(${problemas}) ` : '') + 'Monitoreo';
}

async function cargar() {
    try {
        const r = await fetch('{{ route('monitoreo.datos') }}', { headers: { 'Accept': 'application/json' } });
        pintar(await r.json());
    } catch (e) {
        $('estado').textContent = 'sin conexión';
        $('estado').className = 'estado mal';
    }
}

async function accion(cual, item) {
    const r = await fetch(`/monitoreo/${cual}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ ml_item_id: item }),
    });
    const d = await r.json();
    avisar(d.mensaje);
    cargar();
}

async function sincronizar() {
    const b = $('btn-sync');
    b.disabled = true;
    b.textContent = 'Sincronizando…';
    try {
        const r = await fetch('{{ route('monitoreo.sincronizar') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        });
        avisar((await r.json()).mensaje);
    } finally {
        b.disabled = false;
        b.textContent = 'Sincronizar stock ahora';
        cargar();
    }
}

cargar();
setInterval(cargar, 30000);
</script>
</body>
</html>
