<?php

/*
| CORS solo para el widget: se carga en sitios de terceros (cualquier origen) y no usa cookies.
| El resto de la API es de mismo origen: el navegador habla con Next.js, que reenvía a Laravel.
*/
return [

    'paths' => ['api/widget/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-Visitor-Id'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    // Sin credenciales: el widget se identifica con X-Visitor-Id, nunca con cookies de sesión.
    'supports_credentials' => false,

];
