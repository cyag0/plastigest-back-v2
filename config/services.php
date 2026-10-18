<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),
    ],

    'whatsapp' => [
        // Apagado mientras se rehace como bot (ver WhatsAppService). Con false
        // no se manda ningún pedido y el webhook responde 404, aunque estén
        // las demás variables.
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'access_token' => env('WHATSAPP_CLOUD_API_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        // Token que Meta manda al verificar el webhook. Sin valor por defecto:
        // si falta, la verificación se rechaza (el webhook queda apagado).
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // App secret de Meta para validar la firma X-Hub-Signature-256 de los
        // webhooks entrantes. Sin esto cualquiera podía forjar un webhook.
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
