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
];
