# Plan de limpieza: ProductsSeeder + PackageSeeder

## Decisión

- **No se siembran paquetes.** `product_packages` queda vacío tras el seed.
- El seeder deja solo **productos base limpios** (la unidad mínima vendible).
- Los usuarios crean sus **cajas / promos / mayoreo** dentro de la app, con su precio y
  cantidad reales.
- **Razón:** las cantidades reales (piezas por caja, botellas por pack, etc.) **no están en el
  Excel** (`public/Productos para DiDi.xlsx` es solo una lista de precios). No se inventan.

---

## 1) Productos que se QUITAN del seeder (eran "el empaque", no un producto)

Cada uno desaparece del catálogo sembrado; el usuario lo recreará como **paquete** del producto
base indicado.

| Código a quitar | Producto | Base sobre el que el usuario lo agregará |
|---|---|---|
| `NAT-CM-100` | Coco mayoreo (100 pzs) | un coco |
| `PROM-2RP` | Promo 2 rompopes | `POS-RP-001` Rompope |
| `PROM-2BC` | Promo 2 bolsas de coco | `NAT-CPB-001` Coco partido en bolsa |
| `PROM-HX3` | Promo horchata 3 x | horchata |
| `POS-PB-1L` | Paquete/Botella 1 LTR | `POS-BI-1L` Botella 1 LT individual |
| `POS-PB-500` | Paquete/Botella 500 ML | `POS-BI-500` Botella ½ LT individual |
| `POS-PG-001` | Paquete/Galones | galón |
| `RAL-CCR-10K` | Caja coco rallado 10 KG | `RAL-CR-1K` Coco rallado 1 KG |
| `COC-CN-CJ` | Cocada de nuez caja | `COC-CN-1P` Cocada de nuez |
| `COC-CL-CJ` | Cocada de limón caja | `COC-CL-1P` |
| `COC-CG-CJ` | Cocada greñuda caja | `COC-CG-1P` |
| `COC-CMG-CJ` | Cocada mixta grande caja | `COC-CMG-1P` |
| `COC-CMC-CJ` | Cocada mixta chica caja | `COC-CMC-1P` |
| `DUR-CJ` | Duraznitos caja | `DUR-1P` |
| `LIM-CJ` | Limoncitos caja | `LIM-1P` |
| `PEL-CJ` | Pellizcadas caja | `PEL-1P` |
| `BAR-BM-CJ` | Barra mixta caja | `BAR-BMC-1P` Barra mixta chica |

---

## 2) Excepciones: solo existen como caja (sin pieza base) — A CONFIRMAR

Estos no tienen una versión "1 pza" en la lista, así que si se quitan se pierden del catálogo.
Propuesta: **dejarlos como producto suelto** (tienen precio real) para no perderlos.

| Código | Producto | Precio |
|---|---|---|
| `COC-CH-CJ` | Cocada horneada caja | $100 |
| `DUL-GLL-CJ` | Galletas caja | $75 |
| `DUL-GLL-4P` | Galletas 4 pzs | $25 |

---

## 3) Productos base que QUEDAN (con correcciones)

Todo lo demás se siembra como producto suelto. Correcciones:

**Unidades (bolsa/botella fija → Pieza; granel se queda en kg/litro):**

| Código | Producto | Unidad hoy | Propuesta |
|---|---|---|---|
| `RAL-CR-500` | Coco rallado 1/2 KG | Kilogramo | **Pieza** (bolsa ½ kg) |
| `RAL-CR-1K` | Coco rallado 1 KG | Kilogramo | **Pieza** (bolsa 1 kg) — ¿o se pesa? |
| `DER-COP-1K` | Copra 1 kilo | Kilogramo | **Pieza** (bolsa 1 kg) — confirmar |
| `DER-HC-001` | Harina de coco | Kilogramo | confirmar (bolsa vs granel) |
| `DER-PC-1K` | Pulpa de coco por kilo | Kilogramo | queda (se pesa) |
| `INT-*` granel | agua/pulpa/horchata/tuba | Litro/Kg | quedan (insumos a granel) |

**Duplicado a eliminar:**

| Código | Producto | Acción |
|---|---|---|
| `RAL-BCR-1K` | Bolsa de coco rallado 1 KG ($120) | Eliminar: mismo producto/precio que `RAL-CR-1K`. Dejar uno. |

---

## 4) Otros seeders

- **`PackageSeeder`**: deja de insertar (se vacía o se quita de `DatabaseSeeder`). Las unidades
  Caja / Paquete / Bulto / Promo **se conservan** en `UnitsSeeder` para que el usuario las use al
  crear paquetes en la app.
- **`UnitsSeeder`**: quitar las conversiones globales `1 Caja = 12 piezas` y `1 Paquete = 100 piezas`
  (la cantidad por empaque ahora la define el usuario por producto). `Docena = 12` se queda.
- **`NAT-CPB-001` Coco partido en bolsa**: está como `raw_material` pero se vende. Revisar si debe
  ser `commercial`.

---

## Resultado

- Catálogo sembrado: **productos sueltos limpios**, unidades correctas, sin duplicados, sin
  empaques-disfrazados-de-producto.
- `product_packages`: **vacío**; lo llenan los usuarios con datos reales.
- Pendiente de confirmar: §2 (cocada horneada y galletas como producto suelto) y unidades dudosas
  de §3 (copra, harina, coco rallado 1 kg: ¿bolsa o granel?).
