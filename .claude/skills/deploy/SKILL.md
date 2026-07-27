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

### Cambios en composer.json/composer.lock (dependencia nueva)

Subir ambos archivos, después en el server:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

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
