#!/usr/bin/env python3
"""
Lee una carpeta de exports "Informe de Ventas Detallado" de Contagram y saca de ahi
el detalle de items de las notas de credito/debito, en un JSON que consume
`scripts/importar_items_notas_credito.php`.

    python scripts/nc_items_desde_detallado.py "public/imports/ventas detallado" salida.json

Los rangos exportados suelen solaparse: los items se deduplican por
(codigo, cantidad, precio) dentro de cada nota.

La clave de cada nota es "<tipo>-<id de Contagram>" y NO solo el id: en Contagram
las NC y las ND numeran por separado, asi que hay ids repetidos entre ambas
(2021-NC-10 y 2023-ND-10 existen las dos). Cruzar solo por numero las mezcla.

Las fechas no se usan para nada — el cruce es por id —, asi que el clasico
desastre de abrir el .xlsx en Excel es-AR y que se den vuelta dia y mes no
afecta a este importador.
"""
import glob
import json
import os
import re
import sys

import openpyxl

# Columnas del export (0-based), verificadas contra un archivo real.
COL_ID, COL_TIPO_CBTE, COL_DESCRIPCION, COL_CODIGO = 0, 8, 12, 13
COL_CANTIDAD, COL_PRECIO = 16, 17
COL_SUBTOTAL_SIN_DESC, COL_SUBTOTAL_CON_DESC, COL_TOTAL = 23, 25, 39
# Cada alicuota tiene su propia columna; la que no es cero manda.
COLS_IVA = {29: 2.5, 30: 5.0, 31: 10.5, 32: 21.0, 33: 27.0}


def alicuota(fila):
    for col, pct in COLS_IVA.items():
        if fila[col]:
            return pct
    return None


def descuento_pct(fila):
    sin, con = fila[COL_SUBTOTAL_SIN_DESC], fila[COL_SUBTOTAL_CON_DESC]
    if not sin or con is None:
        return 0.0
    return round((1 - con / sin) * 100, 2)


def main(carpeta, salida):
    notas = {}
    archivos = sorted(glob.glob(os.path.join(carpeta, "*.xlsx")))

    for ruta in archivos:
        libro = openpyxl.load_workbook(ruta, read_only=True, data_only=True)
        filas = list(libro.active.iter_rows(values_only=True))
        libro.close()

        cabecera = [i for i, f in enumerate(filas) if f and str(f[COL_ID]) == "Id"]
        if not cabecera:
            print(f"  sin cabecera, salteado: {os.path.basename(ruta)}")
            continue

        for fila in filas[cabecera[0] + 1:]:
            if not fila or fila[COL_ID] is None:
                continue
            tipo_cbte = str(fila[COL_TIPO_CBTE] or "")
            if not tipo_cbte.startswith(("NC", "ND")):
                continue

            tipo = "credito" if tipo_cbte.startswith("NC") else "debito"
            clave = f"{tipo}-{int(fila[COL_ID])}"
            codigo = str(fila[COL_CODIGO] or "").strip()
            producto = re.match(r"(\d+)", codigo)

            item = {
                "codigo": codigo,
                "producto_id": int(producto.group(1)) if producto else None,
                "descripcion": str(fila[COL_DESCRIPCION] or "")[:255],
                # El export trae todo en negativo (es una nota); el signo lo pone
                # el informe segun el tipo, asi que los items van en positivo.
                "cantidad": abs(float(fila[COL_CANTIDAD] or 0)),
                "precio": abs(float(fila[COL_PRECIO] or 0)),
                "descuento_pct": descuento_pct(fila),
                "iva_pct": alicuota(fila),
                "total": abs(float(fila[COL_TOTAL] or 0)),
            }
            notas.setdefault(clave, {})[(codigo, item["cantidad"], item["precio"])] = item

    salidas = {k: list(v.values()) for k, v in notas.items()}
    with open(salida, "w", encoding="utf-8") as fh:
        json.dump(salidas, fh, ensure_ascii=False)

    print(f"archivos leidos : {len(archivos)}")
    print(f"notas           : {len(salidas)}")
    print(f"items            : {sum(len(v) for v in salidas.values())}")
    print(f"escrito en      : {salida}")


if __name__ == "__main__":
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
