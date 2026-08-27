function toast(tipo, mensaje) {
    if (window.toastr && window.toastr[tipo]) {
        window.toastr[tipo](mensaje);
    }
}

function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.content || '';
}

function enviarJson(url, datos) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify(datos),
    }).then((r) => r.json().then((data) => ({ ok: r.ok, data })));
}

function primerError(err) {
    return err.errors ? Object.values(err.errors)[0]?.[0] : null;
}

document.addEventListener('DOMContentLoaded', function () {
    // Modal "olvidé mi contraseña" (login)
    const formOlvide = document.getElementById('form-olvide-contrasena');
    if (formOlvide && window.LoginConfig) {
        formOlvide.addEventListener('submit', function (e) {
            e.preventDefault();
            const email = formOlvide.querySelector('[name=email]').value;

            enviarJson(window.LoginConfig.rutas.enviarLink, { email })
                .then(({ ok, data }) => {
                    if (!ok) { throw data; }
                    toast('success', data.message);
                    const modalEl = document.getElementById('modal-olvide-contrasena');
                    window.bootstrap?.Modal.getOrCreateInstance(modalEl).hide();
                    formOlvide.reset();
                })
                .catch((err) => {
                    toast('error', primerError(err) || 'No se pudo procesar el pedido.');
                });
        });
    }

    // Formulario de nueva contraseña (link del email)
    const formNueva = document.getElementById('form-nueva-contrasena');
    if (formNueva && window.ResetPasswordConfig) {
        formNueva.addEventListener('submit', function (e) {
            e.preventDefault();
            const datos = {
                token: formNueva.querySelector('[name=token]').value,
                email: formNueva.querySelector('[name=email]').value,
                password: formNueva.querySelector('[name=password]').value,
                password_confirmation: formNueva.querySelector('[name=password_confirmation]').value,
            };

            enviarJson(window.ResetPasswordConfig.rutas.actualizar, datos)
                .then(({ ok, data }) => {
                    if (!ok) { throw data; }
                    toast('success', data.message);
                    setTimeout(function () {
                        window.location.href = window.ResetPasswordConfig.rutas.login;
                    }, 1200);
                })
                .catch((err) => {
                    toast('error', primerError(err) || 'No se pudo actualizar la contraseña.');
                });
        });
    }

    // Modal "cambiar contraseña" (perfil, sesión activa)
    const formPerfil = document.getElementById('form-mi-perfil-contrasena');
    if (formPerfil && window.MiPerfilConfig?.rutas?.actualizarContrasena) {
        formPerfil.addEventListener('submit', function (e) {
            e.preventDefault();
            const datos = {
                password_actual: formPerfil.querySelector('[name=password_actual]').value,
                password: formPerfil.querySelector('[name=password]').value,
                password_confirmation: formPerfil.querySelector('[name=password_confirmation]').value,
            };

            fetch(window.MiPerfilConfig.rutas.actualizarContrasena, {
                method: 'PUT',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify(datos),
            })
                .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok) { throw data; }
                    toast('success', data.message);
                    const modalEl = document.getElementById('modal-mi-perfil-contrasena');
                    window.bootstrap?.Modal.getOrCreateInstance(modalEl).hide();
                    formPerfil.reset();
                })
                .catch((err) => {
                    toast('error', primerError(err) || 'No se pudo actualizar la contraseña.');
                });
        });
    }
});
