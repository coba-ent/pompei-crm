#!/usr/bin/env python3
"""
Descarga storage/app/public del demo (Hostinger) a una carpeta local, para
migrarlo al VPS nuevo. Reusa password_ssh() de deploy.py (misma fuente de
credenciales, nunca hardcodeada acá).

Uso: python scripts/descargar_storage_demo.py
"""
import os
import sys

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RAIZ)

from deploy import password_ssh, HOST, PORT, USER, REMOTE_BASE  # noqa: E402

import paramiko  # noqa: E402

DESTINO_LOCAL = os.path.join(RAIZ, "storage_demo_public")
REMOTO = f"{REMOTE_BASE}/storage/app/public"


def descargar_recursivo(sftp, remoto, local):
    os.makedirs(local, exist_ok=True)
    for item in sftp.listdir_attr(remoto):
        remoto_item = f"{remoto}/{item.filename}"
        local_item = os.path.join(local, item.filename)
        if paramiko.SFTPAttributes.__module__ and (item.st_mode & 0o40000):  # es directorio
            descargar_recursivo(sftp, remoto_item, local_item)
        else:
            print(f"  {remoto_item}")
            sftp.get(remoto_item, local_item)


def main():
    password = password_ssh()
    cliente = paramiko.SSHClient()
    cliente.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    cliente.connect(HOST, port=PORT, username=USER, password=password, timeout=30)
    sftp = paramiko.SFTPClient.from_transport(cliente.get_transport())

    print(f"Descargando {REMOTO} -> {DESTINO_LOCAL}")
    descargar_recursivo(sftp, REMOTO, DESTINO_LOCAL)

    sftp.close()
    cliente.close()
    print("Listo.")


if __name__ == "__main__":
    main()
