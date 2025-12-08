<?php

// Script temporal para obtener información de tu cuenta de WhatsApp Business

$token = 'EAAbKVMJRn9cBQPFXHUUOXCityUk6kgsayZCsjMZClohGZBIyVbIUlpFapFHgyNaLNbeg1svgeDEcCLUsvaQgx7ifGe3c6NvdgLOU7VUDHxAhxUOQLtnbCTcT0ZCfhC2wDPxPKgflAZCPDide0rT38ekHnGOWrOf95odjtIubx3vBLG53vXVrPx2vjeMvcr6k5v0LJrfDZBJ1bbeJWUv60sztZBsVcbDsP3EDZC1NwRAeZCLZAAuaK4Dk6uc5fOpL0b3BTC1fTPwFSnsHBFQzYi9ljf45a7';

// Primero necesitas el WABA ID (WhatsApp Business Account ID)
// Puedes obtenerlo desde: https://business.facebook.com/settings/whatsapp-business-accounts

echo "=== INSTRUCCIONES ===\n\n";
echo "1. Ve a: https://business.facebook.com/settings/whatsapp-business-accounts\n";
echo "2. Copia tu WhatsApp Business Account ID (WABA ID)\n";
echo "3. Luego ejecuta este comando en terminal:\n\n";
echo "curl -X GET \"https://graph.facebook.com/v18.0/{WABA_ID}/phone_numbers?access_token=$token\"\n\n";
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
