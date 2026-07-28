#!/usr/bin/env python3
"""
Deploy al demo productivo en Hostinger (contagramdemo.devstudioweb.com).

Runbook completo y gotchas del hosting: .claude/skills/deploy/SKILL.md
Credenciales: CREDENCIALES_ACCESO.txt (raíz, gitignored) — NO se hardcodean acá.

Uso:
    python deploy.py <archivo> [<archivo> ...]   Sube archivos puntuales
    python deploy.py --build                     Recompila y sube public/build/
    python deploy.py --artisan "<comando>"       Corre un artisan en el server
    python deploy.py --tinker <script.php>       Corre un script PHP en tinker
    python deploy.py --check                     Sólo verifica que el sitio responda

Ejemplos:
    python deploy.py public/css/contagram-custom.css
    python deploy.py app/Models/Venta.php resources/views/ventas/index.blade.php
    python deploy.py --build
    python deploy.py --artisan "migrate --force"
    python deploy.py --tinker scripts/consulta.php

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

        self.transport = paramiko.Transport((HOST, PORT))
        self.transport.connect(username=USER, password=password)
        self.sftp = paramiko.SFTPClient.from_transport(self.transport)

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

        if not os.path.isfile(local):
            print(f"  OMITIDO (no existe local): {ruta_relativa}")
            return False

        remoto = f"{REMOTE_BASE}/{ruta_relativa}"
        carpeta = os.path.dirname(remoto)
        self.ejecutar(f"mkdir -p {carpeta}", mostrar=False)
        self.sftp.put(local, remoto)
        print(f"  OK  {ruta_relativa}  ({os.path.getsize(local)} bytes)")
        return True

    def cerrar(self):
        self.sftp.close()
        self.transport.close()
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
    """Recompila Vite localmente y reemplaza public/build/ completo en el server."""
    print("Compilando assets (npm run build)...")
    resultado = subprocess.run("npm run build", shell=True, cwd=RAIZ)
    if resultado.returncode != 0:
        sys.exit("npm run build falló, no se sube nada.")

    with tempfile.TemporaryDirectory() as tmp:
        tar_local = os.path.join(tmp, "build.tar.gz")
        with tarfile.open(tar_local, "w:gz") as tar:
            tar.add(os.path.join(RAIZ, "public", "build"), arcname="build")

        print("Subiendo public/build/...")
        tar_remoto = f"{REMOTE_BASE}/build_deploy.tar.gz"
        servidor.sftp.put(tar_local, tar_remoto)

    # El build viejo se borra entero: los nombres llevan hash y un archivo
    # colgado de un build anterior puede quedar referenciado por el manifest.
    servidor.ejecutar(f"rm -rf {REMOTE_BASE}/public/build")
    servidor.ejecutar(f"tar -xzf {tar_remoto} -C {REMOTE_BASE}/public")
    servidor.ejecutar(f"rm -f {tar_remoto}")
    servidor.ejecutar("php artisan view:clear")
    servidor.ejecutar("php artisan view:cache")


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
            print("Subiendo archivos...")
            subidos = [r for r in args if servidor.subir(r)]

            if not subidos:
                sys.exit("No se subió ningún archivo.")

            comandos = caches_a_limpiar([r.replace("\\", "/") for r in subidos])
            if comandos:
                print("Regenerando caches...")
                for comando in comandos:
                    servidor.ejecutar(comando)
    finally:
        servidor.cerrar()

    verificar()


if __name__ == "__main__":
    main()
