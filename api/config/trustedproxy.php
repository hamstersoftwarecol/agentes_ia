<?php

/*
| Proxies de confianza (los lee el middleware TrustProxies de Laravel).
|
| Detrás de un proxy (nginx, Cloudflare, el rewrite de Next.js) la IP real del visitante llega en
| X-Forwarded-For. Sin esto, los límites por IP del widget agrupan a TODOS los visitantes en la IP
| del proxy. Poner solo IPs de proxies propios (separadas por coma): con '*' cualquiera que llegue
| directo a la API podría falsear su IP y esquivar los límites.
*/
return [
    'proxies' => env('TRUSTED_PROXIES'),
];
