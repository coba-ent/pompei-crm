---
name: "deploy"
description: "Deploy local changes (código, assets, DB) al demo productivo en Hostinger (contagramdemo.devstudioweb.com) por SSH."
argument-hint: "Opcional: qué cambió (ej. 'solo un blade', 'js de otros-ingresos', 'migración nueva')"
metadata:
  author: "contagram"
user-invocable: true
disable-model-invocation: false
---

## Qué es esto

Runbook para subir cambios locales al demo productivo del CRM en Hostinger, sin tener que
re-explorar el servidor desde cero cada vez. Leé este archivo entero antes de tocar nada — evita
repetir errores ya pisados en el primer deploy.

## Credenciales y acceso

Todo en `CREDENCIALES_ACCESO.txt` (raíz del proyecto, gitignored). Leelo antes de conectar:
- SSH: `ssh -p 65002 u361088648@147.93.37.8` (password en el archivo)
- DB producción: `u361088648_contagramdemo` / user `u361088648_contagramusr` (password en el archivo)
- Dominio: `https://contagramdemo.devstudioweb.com`

**Cómo conectar**: esta máquina (Windows/git-bash) no tiene `sshpass`, `rsync` ni `plink`, y el
tool de Bash no soporta prompts interactivos de password. Usar **Python + paramiko** (ya instalado)
para todo: SSH exec y SFTP put. Patrón:

```python
import paramiko
client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect('147.93.37.8', port=65002, username='u361088648', password='<ver archivo>', timeout=30)
stdin, stdout, stderr = client.exec_command(cmd)
stdout.channel.recv_exit_status()  # esperar a que termine
```

Para subir archivos, usar `paramiko.Transport` + `SFTPClient.from_transport(...).put(local, remote)`.

**Nunca imprimas el contenido de `.env` ni de archivos con la password de la DB** (el classifier de
permisos bloquea comandos que hacen `cat` de secretos — usá `wc -l` o similar para verificar sin
exponer contenido).

## Estructura real en el servidor (IMPORTANTE, no la reinventes)

La app vive en:

```
/home/u361088648/domains/devstudioweb.com/public_html/contagramdemo/
```

Es decir: **subcarpeta de `devstudioweb.com`**, no un dominio/subdominio propio a nivel filesystem.
Es la misma convención que usan `energym`, `trident`, `tucartaonline` en la misma cuenta: el Laravel
completo (artisan, app/, vendor/, public/, etc.) vive directo en esa carpeta, con un `.htaccess` en
la raíz que redirige todo a `/public/`:

```apache
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ /public/$1 [L]
```

**NUNCA crees una carpeta como `domains/contagramdemo.devstudioweb.com/`** (sibling de
`domains/devstudioweb.com/`) pensando que es un subdominio nuevo — Hostinger la borra sola en algún
momento (proceso de sync que limpia carpetas huérfanas no registradas como dominio/subdominio real
en hPanel). Ya pasó una vez y se perdió todo el trabajo de esa carpeta. El subdominio
`contagramdemo.devstudioweb.com` está dado de alta en hPanel apuntando a
`domains/devstudioweb.com/public_html/contagramdemo` — si algún día deja de rutear ahí, es un tema
de panel, no de filesystem, y hay que pedirle al usuario que lo revise en hPanel (Dominios >
Subdominios), no intentar arreglarlo creando carpetas.

`.env` de producción ya está en esa carpeta con `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://contagramdemo.devstudioweb.com` y las credenciales de la DB de arriba. No hace
falta tocarlo salvo que cambie alguna config real.

## Cómo desplegar según el tipo de cambio

### Solo PHP / Blade (controllers, models, views, routes, config)

Subir el/los archivo(s) puntuales por SFTP a la misma ruta relativa dentro de
`domains/devstudioweb.com/public_html/contagramdemo/`. Después, según qué tocaste:

**Namespaces/carpetas nuevas**: `SFTPClient.put()` no crea directorios padre — si el archivo va a
una carpeta que todavía no existe en el server (ej. un módulo nuevo con su propio namespace), primero
hay que crearla con `exec_command('mkdir -p <ruta>')` antes de subir, si no el `put` falla con "No
such file".

```bash
php artisan view:clear && php artisan view:cache      # si tocaste algún .blade.php
php artisan route:clear && php artisan route:cache     # si tocaste routes/*.php
php artisan config:clear && php artisan config:cache   # si tocaste config/*.php o .env
```

No hace falta re-subir todo el proyecto ni reinstalar composer para esto.

### JS/CSS (resources/js/*, resources/css/*)

El servidor **no tiene node/npm** (`node -v` da "command not found"). Vite hay que compilarlo
**localmente**:

```bash
npm run build
```

Esto regenera `public/build/` con hashes nuevos en los nombres de archivo (manifest.json cambia).
Hay que subir **todo `public/build/`** (borrar el viejo en el server y subir el nuevo completo, no
mergear), porque las vistas Blade referencian los assets vía el manifest y un hash viejo colgado
rompe todo:

```python
# local: tar solo del build
tar -czf build_update.tar.gz -C public build
# server:
rm -rf domains/devstudioweb.com/public_html/contagramdemo/public/build
tar -xzf build_update.tar.gz -C domains/devstudioweb.com/public_html/contagramdemo/public
```

Después `php artisan view:clear && php artisan view:cache` (las vistas cacheadas pueden tener
referencias a assets, mejor recompilar). Subí también el `.js`/`.css` fuente a
`resources/js|css/...` en el server para que quede en sync (no se sirve directo, pero si el día de
mañana se corre build en el server o alguien lee el código ahí, que sea el mismo).

**Si estás corriendo esto desde Claude Code**: cualquier paso de este runbook que escriba en el
server de producción por SSH/SFTP —no sólo el `rm -rf` de `public/build`, también una subida SFTP
común y corriente de un solo archivo (ej. un CSS)— puede quedar bloqueado por el classifier de Auto
Mode la primera vez que se ejecuta en una conversación. No es un bloqueo duro: pedile confirmación al
usuario y reintentá el mismo comando — normalmente se destraba. Si vuelve a bloquear después de
confirmar, ahí sí es un bloqueo duro (mismo patrón que editar `.env` o auto-ampliarse permisos): no
insistas, pasale el comando exacto al usuario para que lo corra él mismo.

### Assets estáticos nuevos (imágenes, libs de terceros en public/vendor, etc.)

Subir directo por SFTP al path equivalente dentro de `public/`. Sin pasos extra salvo que estén
referenciados en una vista (ver arriba).

**Gotcha de tar con exclude**: si alguna vez armás un tarball completo del proyecto para redeploy
grande, `--exclude='vendor'` en `tar` matchea **cualquier carpeta llamada `vendor` en cualquier
nivel**, no solo la raíz — así que también te va a excluir `public/vendor` (assets del template
NexaDash: toastr, bootstrap-select, apexcharts, moment, daterangepicker, global.min.js, etc.). Si
hacés eso, subí `public/vendor` aparte. Ya pasó una vez y rompió toda la UI con errores JS
silenciosos (assets 404 pero la página cargaba igual).

### Migraciones nuevas

Subir el archivo de migración a `database/migrations/`, después:

```bash
php artisan migrate --force
```

Es idempotente — si ya está aplicada no hace nada ("Nothing to migrate").

### Seeders nuevos o modificados

Si se agrega un seeder nuevo a `database/seeders/DatabaseSeeder.php`, **nunca correr
`php artisan db:seed` a secas en producción** — dispara TODOS los seeders del archivo, incluido
`UsuarioAdminSeeder`, que puede resetear la contraseña del admin real. Correr siempre la clase
puntual:

```bash
php artisan db:seed --class=NombreDelSeederNuevo --force
```

Verificar que el seeder sea idempotente (que no pise datos ya modificados por el usuario) antes de
subirlo.

### Variables nuevas en `.env` de producción

El punto de arriba ("no hace falta tocar el .env salvo que cambie alguna config real") aplica poco:
**cualquier feature que agregue credenciales de integración, flags o config nueva necesita que se
agreguen esas claves al `.env` de producción**, no solo al local. Antes de dar el deploy por
terminado, diff mental entre `.env.example` (lo que se subió a git) y lo que hay en el server: si
`.env.example` tiene una clave nueva, `.env` de producción también la necesita con su valor real.

Para verificar una clave puntual sin volcar el archivo completo (regla de abajo: nunca imprimir
`.env` completo):

```bash
grep '^NOMBRE_CLAVE=' .env
```

**`CACHE_STORE`**: si la feature usa `Cache::lock()` (locks atómicos, ej. para evitar condiciones de
carrera entre requests concurrentes), verificar que `CACHE_STORE` en producción sea `database` (este
hosting compartido no tiene Redis ni `exec()`). Es el único perfil probado para ese mecanismo — si
está en el default de Laravel (`file` o `database` según versión), confirmarlo explícitamente en vez
de asumir.

Después de tocar `.env`: `php artisan config:clear && php artisan config:cache`.

### Deploy grande (muchos archivos nuevos, varias carpetas nuevas — ej. una spec completa)

Para un cambio chico (1-3 archivos existentes), archivo por archivo por SFTP como arriba está bien.
Para un deploy grande (una spec entera: decenas de archivos, varias carpetas/namespaces nuevos), **no
enumeres archivo por archivo** — es lento y muy fácil olvidarse uno (pasó: un controller nuevo quedó
afuera de la lista a mano y rompió una pantalla en silencio hasta que se probó con `curl`). En su
lugar, tarear los directorios de código fuente completos y extraer el tar entero en el server:

```bash
# local
tar -czf app_update.tar.gz app config database/migrations database/seeders resources/views resources/js routes bootstrap/app.php
```

```python
# server (via paramiko, mismo patrón de arriba)
sftp.put(local_tar, remote_base + '/app_update.tar.gz')
# exec_command:
tar -xzf app_update.tar.gz -C <remote_base>   # sobreescribe todo lo que ya existía + crea lo nuevo
rm -f app_update.tar.gz
```

Esto sobreescribe `app/`, `config/`, etc. enteros con la versión local — no hace falta `mkdir -p` por
carpeta nueva (el propio `tar -x` las crea), y no hay riesgo de olvidarse un archivo porque no se
arma una lista a mano. **No incluyas `database/seeders` a ciegas si tenés miedo de pisar algo en
`DatabaseSeeder.php`** — de última, subilo aparte y revisalo antes. No incluyas `tests/`, `specs/`,
`docs/` (no hace falta en el server) ni nada bajo `storage/`/`vendor/`/`node_modules/` (ver el gotcha
de `--exclude=vendor` más abajo, que aplica igual si armás un tar así de manera más amplia).

### Cambios en composer.json/composer.lock (dependencia nueva)

Subir ambos archivos, después en el server:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

## Gotchas de rutas y scheduler (no repetir)

- **Orden de rutas con segmento genérico (`{param}`) dentro de un `prefix()` group**: si dentro del
  mismo grupo hay una ruta con un segmento comodín de un solo nivel (ej. `Route::get('{orden}', ...)`)
  y **después** un sub-grupo con un prefijo literal (ej. `Route::prefix('vinculaciones')->group(...)`),
  el comodín matchea primero y el sub-grupo queda inalcanzable (404 silencioso, la vista nunca se
  renderiza). Regla: **los sub-grupos con prefijo literal van siempre antes que las rutas con
  `{param}` de un solo segmento**, dentro del mismo `prefix()`. Pasó con `ingresos/mercadolibre/{orden}`
  vs `ingresos/mercadolibre/vinculaciones` (deploy specs 012/013, 28/07/2026) — se detectó recién
  probando la URL con `curl`, no con `php artisan route:list` (ahí se ve bien, el problema es sólo de
  orden de matching en runtime).
- **`->withSchedule()` en `bootstrap/app.php` no hace nada solo**: registrar comandos con
  `$schedule->command(...)->everyMinute()` no ejecuta nada por sí mismo en producción. Hostinger (y la
  mayoría de los hostings compartidos) **no expone `crontab`** por SSH (`crontab -l`/`-e` dan
  "command not found") — el cron se configura **desde hPanel** ("Avanzado → Cron Jobs"), apuntando a
  `php /home/.../contagramdemo/artisan schedule:run` cada minuto. Sin ese cron creado a mano en el
  panel, los botones manuales ("Sincronizar ahora") funcionan igual (son requests HTTP normales), pero
  la sincronización **programada** no corre nunca. Avisarle esto al usuario explícitamente después de
  cualquier deploy que agregue algo a `withSchedule()` — no es algo que se pueda verificar ni arreglar
  por SSH.

## Gotchas del hosting (no repetir)

- **`exec()` está deshabilitado** en este hosting compartido. `php artisan storage:link` falla con
  "Call to undefined function Illuminate\Filesystem\exec()". El symlink ya está creado a mano:
  `public/storage -> ../storage/app/public`. No hace falta volver a correrlo salvo que se borre el
  symlink, en cuyo caso recrealo manual con `ln -s`.
- **`storage/logs` y las subcarpetas de `storage/framework/`** (`cache/data`, `sessions`, `views`,
  `testing`) tienen que existir con permisos `775` — si armás un tar excluyendo `storage/logs` (para
  no subir logs viejos), asegurate de crear la carpeta vacía en el server igual, si no Laravel no
  puede loguear errores ni arranca bien la sesión.
- El servidor está detrás del CDN de Hostinger (`hcdn`) — las respuestas normalmente son
  `x-hcdn-cache-status: DYNAMIC` para HTML (no cachea páginas dinámicas), así que no hace falta
  purgar caché de CDN después de un deploy típico.

## Verificación post-deploy

Como mínimo, después de cualquier deploy:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://contagramdemo.devstudioweb.com/
```

Si el cambio es visual o de JS, hacer login por curl (guardando cookies) y pegarle a la pantalla
afectada para confirmar 200 y que el asset nuevo esté referenciado:

```bash
curl -s -c cookies.txt https://contagramdemo.devstudioweb.com/login -o login.html
TOKEN=$(grep -o 'name="_token" value="[^"]*"' login.html | sed 's/.*value="//;s/"//')
curl -s -b cookies.txt -c cookies.txt -X POST https://contagramdemo.devstudioweb.com/login \
  -d "_token=$TOKEN" -d "email=admin@contagram.local" -d "password=<ver CREDENCIALES_ACCESO.txt>"
curl -s -b cookies.txt https://contagramdemo.devstudioweb.com/<pantalla-afectada> -o /dev/null -w '%{http_code}\n'
```

No hace falta un browser real para verificar esto — curl alcanza para confirmar que no hay 404/500.
