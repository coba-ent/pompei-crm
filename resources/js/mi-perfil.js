function toast(tipo, mensaje) {
    if (window.toastr && window.toastr[tipo]) {
        window.toastr[tipo](mensaje);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const rutas = window.MiPerfilConfig.rutas;
    const form = document.getElementById('form-mi-perfil');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(form);

        fetch(rutas.guardar, {
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
            .catch((err) => {
                const primerError = err.errors ? Object.values(err.errors)[0]?.[0] : null;
                toast('error', primerError || err.message || 'No se pudo guardar Mi Perfil.');
            });
    });
});
