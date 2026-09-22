<?php
return [
    'app' => [
        'url' => 'https://ingressos.seudominio.com.br',
        'base_path' => '',
        'timezone' => 'America/Sao_Paulo',
        'debug' => false,
        'session_secure' => true,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpanelusuario_acquavale',
        'user' => 'cpanelusuario_acquavale',
        'password' => 'TROQUE_AQUI',
    ],
    'admin' => [
        'email' => 'admin@seudominio.com.br',
        'password_hash' => '$2y$12$SUBSTITUA_POR_HASH_GERADO',
    ],
    'api' => [
        'key' => 'SUBSTITUA_POR_UMA_CHAVE_LONGA_E_ALEATORIA',
        'claim_ttl_minutes' => 10,
    ],
    'uploads' => [
        'max_photo_mb' => 8,
    ],
    // 'expresso' is the production default because it uses the same reservation
    // details flow that already works in the local installation. iPlate remains optional.
    'reservation' => ['provider' => 'expresso'],
    'expresso' => [
        'token_url' => 'https://vale.expresso.app/api/obter_token',
        'reservation_url' => 'https://vale.expresso.app/api/reserva',
        'user' => '',
        'password' => '',
        'timeout_seconds' => 20,
    ],
    'iplate' => [
        'server_url' => 'https://vale.expresso.app/iplate/backend/api/vehicle-entry-create.php',
        'username' => 'TROQUE_AQUI',
        'password' => 'TROQUE_AQUI',
        'timeout_seconds' => 20,
    ],
];
