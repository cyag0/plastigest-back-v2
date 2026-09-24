# Checklist de modificaciones — GCStock

Basado en el documento **“Modificaciones GCStock”**.

> Lo resaltado en amarillo en el PDF corresponde a lo ya terminado: puntos **1, 2, 3, 4, 6 y 7**
> completos, y el **5** solo parcialmente (el reporte sí, la actualización del cierre no).
> Del **8** en adelante no hay resaltado, así que siguen pendientes.

## Compras

- [x] **1. Permitir modificar la cantidad de compra desde el carrito**
    - [x] Permitir editar manualmente la cantidad de cada producto agregado al carrito.
    - [x] Soportar compras de cantidades grandes, por ejemplo de **100 a 5,000 cocos**.

## Producción

- [x] **2. Mostrar todos los productos disponibles en Producción**
    - [x] Corregir el listado de productos que pueden seleccionarse.
    - [x] Verificar que aparezca, por ejemplo, **“Pieza de pulpa de Coco”**.

- [ ] **8. Corregir producción de bebidas embotelladas**
    - [ ] Verificar fórmulas que incluyen botellas y tapaderas.
    - [ ] Corregir la creación de producciones como **“Aguas de litro”**.
    - [ ] Evitar que las producciones queden incorrectamente como **“borradores”**.
    - [ ] Verificar el manejo de los insumos catalogados como **materia prima**.

## Inventario

- [x] **3. Corregir buscador en Inventario > Paquetes**
    - [x] Permitir buscar productos específicos correctamente.

- [ ] **14. Corregir creación de productos**
    - [ ] Resolver el error que aparece al crear un nuevo producto aun cuando los datos están completos.

- [ ] **15. Corregir Inventario > Ajustes**
    - [ ] Permitir crear un ajuste nuevo.
    - [ ] Permitir agregar productos después de confirmar la fecha.
    - [ ] Resolver el error que impide continuar con el ajuste.

- [ ] **16. Restringir inventarios visibles según sucursal**
    - [ ] Los usuarios básicos solo deben ver inventarios de su sucursal.
    - [ ] Los administradores pueden conservar acceso a inventarios de todas las sucursales.

## Ventas

- [x] **4. Corregir estado de pago de ventas a crédito**
    - [x] Si una venta es a crédito y se registra un pago de **$0**, no debe marcarse como **“Pagado”**.
    - [x] Evitar que este comportamiento afecte el conteo de efectivo del cierre de caja.

- [ ] **10. Revisar lógica entre ventas, compras y saldo de caja**
    - [ ] Evitar que una compra grande deje un saldo negativo que se arrastre incorrectamente entre cortes.
    - [ ] Mantener separado el total real de ventas del día/turno respecto a deudas o compras anteriores.
    - [ ] Definir si una compra afecta o no el efectivo disponible de caja.
    - [ ] Permitir especificar si el dinero utilizado para pagar una compra **salió de caja o no**.

## Caja / Cierre de caja

> **Punto 5: PARCIAL.** El reporte está terminado; la actualización del cierre sigue pendiente.

- [x] **5. Generar reporte de cierre de caja**
    - [x] Generar un reporte con la información del corte del día.
    - [x] Permitir imprimir el reporte.
    - [x] Permitir enviarlo por Gmail/correo electrónico.

- [ ] **5.1 Corregir actualización del cierre** ← pendiente
    - [ ] Actualizar correctamente los **ingresos**.
    - [ ] Actualizar correctamente los **egresos**.
    - [ ] Actualizar correctamente los **movimientos**.
    - [ ] Reflejar esos datos correctamente en el cierre final.

## Usuarios, sucursales y roles

- [x] **6. Corregir creación y asignación de usuarios**
    - [x] Asignar correctamente la empresa al crear un usuario.
    - [x] Asignar correctamente la sucursal.
    - [x] Permitir seleccionar/asignar una sucursal.
    - [x] Asignar automáticamente los permisos correspondientes al rol.
    - [x] Corregir el acceso de usuarios existentes a sucursales que ya tienen asignadas.

- [ ] **12. Corregir funcionamiento de roles**
    - [ ] Evitar que los roles recién creados aparezcan como **inactivos** al asignarlos a un usuario.
    - [ ] Verificar que los permisos del rol funcionen correctamente.
    - [ ] Confirmar que el problema quede resuelto, ya que el documento indica que **sigue sin funcionar**.

## Transferencias

- [x] **7. Ajustar selector de sucursales**
    - [x] Corregir la visualización/comportamiento del `select` de sucursales en el módulo de transferencias.

- [ ] **11. Mejorar historial y cantidades de transferencias**
    - [ ] Evaluar/agregar un botón de **Eliminar** en el historial de transferencias.
    - [ ] Corregir las transferencias para que utilicen exactamente la cantidad seleccionada.
    - [ ] Verificar la cantidad mostrada como transferida.
        > Nota: la descripción de este punto queda incompleta en el PDF después de “muestra que se transfirió…”.

- [ ] **13. Corregir visualización del carrito en servidor**
    - [ ] Hacer que los productos del carrito se visualicen correctamente en producción/servidor.
    - [ ] Igualar el comportamiento y presentación observados en localhost.

## Inicio / Dashboard

- [ ] **9. Sincronizar estadísticas del Inicio**
    - [ ] Actualizar estadísticas al realizar compras.
    - [ ] Actualizar estadísticas al realizar ventas.
    - [ ] Actualizar estadísticas al realizar movimientos.
    - [ ] Mantener consistencia entre **Inicio** y **Dashboard**.

- [ ] **9.1 Corregir tareas pendientes**
    - [ ] Al completar una tarea, actualizar su estado correctamente.
    - [ ] Evitar que tareas completadas sigan apareciendo estáticas como pendientes en Inicio.

---

# Sugerencias / Mejoras

## Ventas a crédito

- [ ] Agregar un **estado de pago** para ventas a crédito.
- [ ] Una venta a crédito no debe registrar el importe como dinero recibido mientras no se haya pagado.
- [ ] Registrar de inmediato la salida del producto del inventario.
- [ ] Permitir cambiar posteriormente el estado de pago de la venta.
- [ ] Relacionar las ventas a crédito con un **cliente**.

## Tickets de venta

- [ ] Permitir generar un **ticket** al completar una venta.
- [ ] Preparar el formato para impresión en **impresora térmica** de sucursal.

## Paquetes

- [ ] Permitir agregar **varios productos diferentes** dentro de un mismo paquete.
- [ ] Permitir configurar variedad y cantidades de productos por paquete.

---

## Seguimiento

- [ ] Revisar cambios en desarrollo/local.
- [ ] Probar cambios en servidor.
- [ ] Validar permisos con usuario básico.
- [ ] Validar permisos con administrador.
- [ ] Probar operaciones completas de compra → inventario → venta → caja.
- [ ] Probar producción → consumo de materia prima → producto generado.
- [ ] Probar transferencia entre sucursales.
- [ ] Realizar regresión general antes de liberar cambios.
