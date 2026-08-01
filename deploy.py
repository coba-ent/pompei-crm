#!/usr/bin/env python3
"""
Deploy al demo productivo en Hostinger (contagramdemo.devstudioweb.com).

Runbook completo y gotchas del hosting: .claude/skills/deploy/SKILL.md
Credenciales: CREDENCIALES_ACCESO.txt (raíz, gitignored) — NO se hardcodean acá.

Uso:
    python deploy.py <archivo> [<archivo> ...]   Sube archivos puntuales
    python deploy.py --changed                   Sube lo que git ve modificado/nuevo (deployable)
    python deploy.py --build                     Recompila y sube public/build/
    python deploy.py --artisan "<comando>"       Corre un artisan en el server
    python deploy.py --tinker <script.php>       Corre un script PHP en tinker
    python deploy.py --check                     Sólo verifica que el sitio responda

Ejemplos:
    python deploy.py public/css/contagram-custom.css
    python deploy.py app/Models/Venta.php resources/views/ventas/index.blade.php
    python deploy.py --changed
    python deploy.py --build
    python deploy.py --artisan "migrate --force"
    python deploy.py --tinker scripts/consulta.php

`--changed` evita tipear la lista de archivos a mano (fuente típica de bugs: un
archivo nuevo que se queda afuera de la lista y rompe una pantalla en silencio
hasta que alguien la prueba). Toma `git status --porcelain`, descarta lo que no
hace falta en el server (tests/, specs/, docs/, *.md, database/seeders/,
public/build/) y sube el resto. Los borrados (`D` en git status) NO se aplican
en el server — avisa y hay que borrarlos a mano. Revisá la lista impresa antes
de confiar en que está completa; si te agarra a mitad de un WIP sin relación
con el deploy, subí los archivos puntuales en vez de `--changed`.

`--tinker` existe porque pasar PHP inline por `--artisan "tinker --execute=..."`
se rompe con el escapeo de `$` a través de las capas de shell. Con un archivo
local no hay escapeo de por medio: se sube, se ejecuta y se borra del server.

Las rutas son relativas a la raíz del proyecto y se replican igual en el server.
Los directorios que falten se crean solos (SFTP.put no los crea por su cuenta).
Después de subir, limpia los caches de Laravel que correspondan según el tipo
de archivo (blade -> view, routes -> route, config/.env -> config).
"""

import os
import re
import subprocess
import sys
import tarfile
import tempfile

try:
    import paramiko
except ImportError:
    sys.exit("Falta paramiko. Instalalo con: pip install paramiko")

HOST = "147.93.37.8"
PORT = 65002
USER = "u361088648"
REMOTE_BASE = "/home/u361088648/domains/devstudioweb.com/public_html/contagramdemo"
DOMINIO = "https://contagramdemo.devstudioweb.com"

RAIZ = os.path.dirname(os.path.abspath(__file__))
ARCHIVO_CREDENCIALES = os.path.join(RAIZ, "CREDENCIALES_ACCESO.txt")


def password_ssh():
    """Lee la password de SSH de CREDENCIALES_ACCESO.txt (gitignored)."""
    if not os.path.isfile(ARCHIVO_CREDENCIALES):
        sys.exit(f"No encuentro {ARCHIVO_CREDENCIALES}. Ver .claude/skills/deploy/SKILL.md")

    with open(ARCHIVO_CREDENCIALES, encoding="utf-8", errors="replace") as f:
        contenido = f.read()

    # La línea siguiente al comando `ssh -p 65002 u361088648@147.93.37.8` es la password.
    patron = rf"ssh\s+-p\s+{PORT}\s+{re.escape(USER)}@{re.escape(HOST)}\s*\n\s*(\S+)"
    match = re.search(patron, contenido)
    if not match:
        sys.exit("No pude extraer la password de SSH de CREDENCIALES_ACCESO.txt "
                 "(se espera la password en la línea siguiente al comando ssh).")

    return match.group(1)


class Servidor:
    def __init__(self, password):
        self.cliente = paramiko.SSHClient()
        self.cliente.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        self.cliente.connect(HOST, port=PORT, username=USER, password=password, timeout=30)

        # Reutiliza el transport de la conexión SSH ya abierta en vez de abrir
        # una segunda conexión independiente para SFTP: menos handshakes, y no
        # hay riesgo de que una de las dos conexiones falle y la otra no.
        self.sftp = paramiko.SFTPClient.from_transport(self.cliente.get_transport())

    def ejecutar(self, comando, mostrar=True):
        _, stdout, stderr = self.cliente.exec_command(f"cd {REMOTE_BASE} && {comando}")
        codigo = stdout.channel.recv_exit_status()
        salida = stdout.read().decode(errors="replace").strip()
        error = stderr.read().decode(errors="replace").strip()

        if mostrar:
            print(f"  $ {comando}")
            if salida:
                print("    " + salida.replace("\n", "\n    "))
            if error:
                print("    [stderr] " + error.replace("\n", "\n    "))

        return codigo, salida, error

    def subir(self, ruta_relativa):
        ruta_relativa = ruta_relativa.replace("\\", "/").lstrip("./")
        local = os.path.join(RAIZ, ruta_relativa.replace("/", os.sep))

        # Aborta todo el deploy en vez de "OMITIDO" y seguir: un archivo
        # faltante casi siempre es un typo, y subir el resto igual deja el
        # server en un estado mixto (código nuevo que asume un archivo que
        # nunca llegó) sin que nadie se entere hasta que falla en producción.
        if not os.path.isfile(local):
            sys.exit(f"No existe local, abortando (no se sube nada a medias): {ruta_relativa}")

        remoto = f"{REMOTE_BASE}/{ruta_relativa}"
        carpeta = os.path.dirname(remoto)
        self.ejecutar(f"mkdir -p {carpeta}", mostrar=False)
        self.sftp.put(local, remoto)
        print(f"  OK  {ruta_relativa}  ({os.path.getsize(local)} bytes)")
        return True

    def cerrar(self):
        self.sftp.close()
        self.cliente.close()


def caches_a_limpiar(rutas):
    """Qué caches de Laravel hay que regenerar según qué archivos se tocaron."""
    comandos = []
    if any(r.endswith(".blade.php") for r in rutas):
        comandos += ["php artisan view:clear", "php artisan view:cache"]
    if any(r.startswith("routes/") for r in rutas):
        comandos += ["php artisan route:clear", "php artisan route:cache"]
    if any(r.startswith("config/") for r in rutas):
        comandos += ["php artisan config:clear", "php artisan config:cache"]
    return comandos


def desplegar_build(servidor):
    """Recompila Vite localmente y reemplaza public/build/ completo en el server.

    Swap atómico: extrae a public/build_nuevo/ (staging, sin tocar lo que está
    sirviendo tráfico), verifica que el manifest haya llegado bien, y recién
    ahí hace el `mv`. Nunca hay una ventana en la que public/build no exista o
    esté a medio escribir — antes hacía rm -rf del build viejo ANTES de saber
    si el nuevo se había extraído bien, así que un tar corrupto o una falla de
    extracción dejaba el sitio caído sin build alguno y sin forma de revertir.
    """
    print("Compilando assets (npm run build)...")
    resultado = subprocess.run("npm run build", shell=True, cwd=RAIZ)
    if resultado.returncode != 0:
        sys.exit("npm run build falló, no se sube nada.")

    manifest_local = os.path.join(RAIZ, "public", "build", "manifest.json")
    if not os.path.isfile(manifest_local):
        sys.exit("npm run build no generó public/build/manifest.json, no se sube nada.")

    with tempfile.TemporaryDirectory() as tmp:
        tar_local = os.path.join(tmp, "build.tar.gz")
        with tarfile.open(tar_local, "w:gz") as tar:
            tar.add(os.path.join(RAIZ, "public", "build"), arcname="build_nuevo")

        print("Subiendo public/build/...")
        tar_remoto = f"{REMOTE_BASE}/build_deploy.tar.gz"
        servidor.sftp.put(tar_local, tar_remoto)

    print("Extrayendo a public/build_nuevo/ (staging, sin tocar el build actual)...")
    codigo, _, error = servidor.ejecutar(
        f"rm -rf {REMOTE_BASE}/public/build_nuevo && tar -xzf {tar_remoto} -C {REMOTE_BASE}/public"
    )
    servidor.ejecutar(f"rm -f {tar_remoto}", mostrar=False)
    if codigo != 0:
        servidor.ejecutar(f"rm -rf {REMOTE_BASE}/public/build_nuevo", mostrar=False)
        sys.exit(f"La extracción falló en el server; el build/ actual sigue intacto y sirviendo.\n{error}")

    codigo, _, _ = servidor.ejecutar(f"test -f {REMOTE_BASE}/public/build_nuevo/manifest.json", mostrar=False)
    if codigo != 0:
        servidor.ejecutar(f"rm -rf {REMOTE_BASE}/public/build_nuevo", mostrar=False)
        sys.exit("El build subido no trae manifest.json; algo salió mal. El build/ actual sigue intacto.")

    print("Swap atómico de public/build...")
    codigo, _, error = servidor.ejecutar(
        f"rm -rf {REMOTE_BASE}/public/build_anterior && "
        f"mv {REMOTE_BASE}/public/build {REMOTE_BASE}/public/build_anterior && "
        f"mv {REMOTE_BASE}/public/build_nuevo {REMOTE_BASE}/public/build"
    )
    if codigo != 0:
        sys.exit(
            "El swap falló a mitad de camino — revisá el server a mano: "
            f"public/build_anterior puede tener el build viejo para restaurar con mv.\n{error}"
        )
    servidor.ejecutar(f"rm -rf {REMOTE_BASE}/public/build_anterior", mostrar=False)

    servidor.ejecutar("php artisan view:clear")
    servidor.ejecutar("php artisan view:cache")


RUTAS_DEPLOYABLES = ("app/", "resources/", "routes/", "config/", "database/migrations/", "public/", "bootstrap/app.php")
RUTAS_EXCLUIDAS = ("tests/", "specs/", "docs/", "database/seeders/", "public/build/")


def archivos_cambiados():
    """Deriva del `git status` qué archivos hacen falta en el server, en vez de
    tipearlos a mano (ver nota de --changed en el docstring del módulo)."""
    resultado = subprocess.run(
        ["git", "status", "--porcelain"], cwd=RAIZ, capture_output=True, text=True, check=True,
    )

    rutas = []
    borrados = []
    for linea in resultado.stdout.splitlines():
        estado, ruta = linea[:2], linea[3:].strip()
        ruta = ruta.replace("\\", "/")

        if "D" in estado:
            borrados.append(ruta)
            continue
        if ruta.endswith(".md") or ruta.startswith(RUTAS_EXCLUIDAS):
            continue
        if not ruta.startswith(RUTAS_DEPLOYABLES):
            continue

        # Carpetas nuevas no trackeadas: git las devuelve como una sola entrada
        # "carpeta/" (sin recursar), pero el deploy sube archivos puntuales —
        # se expande a los archivos reales de adentro en vez de abortar en el
        # chequeo de os.path.isfile() más abajo.
        absoluto = os.path.join(RAIZ, ruta.replace("/", os.sep))
        if ruta.endswith("/") and os.path.isdir(absoluto):
            for raiz_dir, _, archivos in os.walk(absoluto):
                for nombre in archivos:
                    completo = os.path.join(raiz_dir, nombre)
                    relativo = os.path.relpath(completo, RAIZ).replace("\\", "/")
                    rutas.append(relativo)
            continue

        rutas.append(ruta)

    if borrados:
        print("ATENCION: git muestra archivos borrados localmente que --changed NO borra en el server:")
        for r in borrados:
            print(f"  - {r}")
        print("Borralos a mano por SSH si corresponde.\n")

    return rutas


def verificar():
    print("\nVerificando el sitio...")
    resultado = subprocess.run(
        f'curl -s -o /dev/null -w "%{{http_code}}" {DOMINIO}/',
        shell=True, capture_output=True, text=True,
    )
    codigo = resultado.stdout.strip()
    print(f"  {DOMINIO}/ -> HTTP {codigo}")
    if codigo not in ("200", "302"):
        print("  ATENCION: el sitio no respondió como se esperaba.")


def main():
    args = sys.argv[1:]
    if not args:
        sys.exit(__doc__)

    if args[0] == "--check":
        verificar()
        return

    if args[0] == "--changed":
        args = archivos_cambiados()
        if not args:
            sys.exit("git no muestra archivos deployables pendientes (revisá git status).")
        print(f"Archivos detectados por git ({len(args)}):")
        for r in args:
            print(f"  {r}")
        print()

    servidor = Servidor(password_ssh())
    try:
        if args[0] == "--build":
            desplegar_build(servidor)

        elif args[0] == "--artisan":
            if len(args) < 2:
                sys.exit("Falta el comando. Ej: python deploy.py --artisan \"migrate --force\"")
            servidor.ejecutar(f"php artisan {args[1]}")

        elif args[0] == "--tinker":
            if len(args) < 2:
                sys.exit("Falta el script. Ej: python deploy.py --tinker scripts/consulta.php")

            local = args[1] if os.path.isabs(args[1]) else os.path.join(RAIZ, args[1])
            if not os.path.isfile(local):
                sys.exit(f"No existe el script: {local}")

            remoto = f"{REMOTE_BASE}/_tinker_temporal.php"
            servidor.sftp.put(local, remoto)
            try:
                servidor.ejecutar("php artisan tinker < _tinker_temporal.php")
            finally:
                servidor.ejecutar(f"rm -f {remoto}", mostrar=False)

        else:
            # Chequea que TODOS existan antes de subir el primero: un typo en
            # el archivo 5 de 6 no debe dejar el server con los primeros 4 ya
            # actualizados y el resto de la corrida cortada a mitad de camino.
            faltantes = [r for r in args if not os.path.isfile(os.path.join(RAIZ, r.replace("\\", "/").replace("/", os.sep)))]
            if faltantes:
                sys.exit("No existen localmente, abortando (no se sube nada a medias): " + ", ".join(faltantes))

            print("Subiendo archivos...")
            for r in args:
                servidor.subir(r)

            comandos = caches_a_limpiar([r.replace("\\", "/") for r in args])
            if comandos:
                print("Regenerando caches...")
                for comando in comandos:
                    codigo, _, error = servidor.ejecutar(comando)
                    if codigo != 0:
                        print(f"  ATENCION: '{comando}' falló (exit {codigo}) — puede que uno de los "
                              "archivos subidos tenga un error de sintaxis.")
    finally:
        servidor.cerrar()

    verificar()


if __name__ == "__main__":
    main()
