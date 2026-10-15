<?php

// Script temporal para obtener información de tu cuenta de WhatsApp Business.
//
// El token NO va en el código (el repositorio es público): se lee de la
// configuración, que a su vez lo toma de la variable de entorno
// WHATSAPP_CLOUD_API_TOKEN (ver config/services.php). Este script nunca lo
// imprime; el comando de ejemplo usa la variable de shell.

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = (string) config('services.whatsapp.access_token');

if ($token === '') {
    fwrite(STDERR, "Falta WHATSAPP_CLOUD_API_TOKEN en el entorno (.env). Defínelo y vuelve a intentar.\n");
    exit(1);
}

// Primero necesitas el WABA ID (WhatsApp Business Account ID)
// Puedes obtenerlo desde: https://business.facebook.com/settings/whatsapp-business-accounts

echo "=== INSTRUCCIONES ===\n\n";
echo "Token de WhatsApp: configurado (no se muestra).\n\n";
echo "1. Ve a: https://business.facebook.com/settings/whatsapp-business-accounts\n";
echo "2. Copia tu WhatsApp Business Account ID (WABA ID)\n";
echo "3. Exporta el token desde tu .env y ejecuta este comando en terminal:\n\n";
echo "   export WHATSAPP_CLOUD_API_TOKEN=\"\$(grep '^WHATSAPP_CLOUD_API_TOKEN=' .env | cut -d= -f2-)\"\n";
echo "   curl -G \"https://graph.facebook.com/v18.0/{WABA_ID}/phone_numbers\" -H \"Authorization: Bearer \$WHATSAPP_CLOUD_API_TOKEN\"\n\n";
echo "Reemplaza {WABA_ID} con tu ID real.\n\n";
echo "=== RESPUESTA ESPERADA ===\n";
echo "{\n";
echo "  \"data\": [\n";
echo "    {\n";
echo "      \"verified_name\": \"Tu Negocio\",\n";
echo "      \"display_phone_number\": \"+51 987 654 321\",\n";
echo "      \"id\": \"123456789012345\",  <-- Este es tu PHONE_NUMBER_ID\n";
echo "      \"quality_rating\": \"GREEN\"\n";
echo "    }\n";
echo "  ]\n";
echo "}\n";
