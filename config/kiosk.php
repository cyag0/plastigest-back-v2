<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kiosko de autoservicio
    |--------------------------------------------------------------------------
    |
    | order_ttl_minutes: minutos que un pedido del kiosko espera a que lo
    | cobren en caja con su mercancía apartada. Después, kiosk:expire-orders
    | (cada 10 minutos) lo cancela y libera lo apartado. Las respuestas lo
    | mandan como expires_at = created_at + estos minutos.
    |
    */

    'order_ttl_minutes' => (int) env('KIOSK_ORDER_TTL_MINUTES', 120),

    /*
    | max_pending_orders: pedidos del kiosko que pueden esperar pago a la vez
    | en una sucursal. Cada uno aparta mercancía: sin tope, alguien en el
    | kiosko podría apartar el inventario de la tienda hasta que vencieran.
    | Al llegar al tope el kiosko responde 422 kiosk_busy.
    */

    'max_pending_orders' => (int) env('KIOSK_MAX_PENDING_ORDERS', 30),
];
