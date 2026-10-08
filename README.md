# Hamster AI Agents

[![CI](https://github.com/hamstersoftwarecol/agentesiaHamstersoftware/actions/workflows/ci.yml/badge.svg)](https://github.com/hamstersoftwarecol/agentesiaHamstersoftware/actions/workflows/ci.yml)

SaaS para que una empresa cree agentes de IA con su propia información, los instale en su sitio web,
capture leads e intervenga en las conversaciones. Producto de Hamster Software.

| Carpeta | Qué es |
|---|---|
| `api/` | Laravel 12 · Sanctum (sesión por cookie) · Pest · PostgreSQL |
| `web/` | Next.js 16 (App Router, Cache Components) · TypeScript · Tailwind 4 |
| `scripts/` | Utilidades de desarrollo (`db.sh`) |
| `docs/` | Arquitectura y decisiones |

Arquitectura, multi-tenancy y capa de IA: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Requisitos

PHP 8.4 + Composer, Node 22+, PostgreSQL 16 (Homebrew: `postgresql@16`) con la extensión **pgvector**.

## Arranque local

```bash
# 1. Base de datos (cluster propio en el puerto 5433, datos en ~/.hamster/pgdata)
scripts/db.sh init          # la primera vez; después: scripts/db.sh start
scripts/install-pgvector.sh # la primera vez: compila pgvector contra el PG16 de Homebrew

# 2. API
cd api
cp .env.example .env && php artisan key:generate
composer install
php artisan migrate --seed  # --seed crea demo@hamster.test / password (solo desarrollo)
php artisan storage:link
composer dev                # API (:8000) + worker de colas + planificador. O por separado:
                            #   php artisan serve --port=8000
                            #   php artisan queue:listen --tries=3 --timeout=0 --sleep=1
                            #   php artisan schedule:work

# 3. Web (otra terminal)
cd web
npm install
cp .env.example .env.local
npm run dev                 # http://localhost:3000
```

El navegador solo habla con `localhost:3000`; Next reenvía `/api/*` a Laravel (`API_URL`).

**El worker de colas es obligatorio:** los documentos de la base de conocimiento se procesan
(extraer texto, trocear, generar vectores) en segundo plano; sin worker se quedan en «En cola».
El **planificador** (`schedule:work` en desarrollo; en producción un cron `* * * * * php artisan schedule:run`) envía a diario
los avisos de fin de prueba; sin él esos correos no salen.

Al actualizar el código: `php artisan migrate` (no `migrate:fresh`).

En desarrollo el correo se escribe en `api/storage/logs/laravel.log` (driver `log`):
ahí aparece el enlace de verificación y el de recuperación de contraseña.

## Tests

```bash
cd api && vendor/bin/pint --test && php artisan test   # usa la base hamster_test
cd web && npx next typegen && npx tsc --noEmit && npx eslint src && npm run build
```

`next typegen` genera los tipos de rutas (`LayoutProps`…); en un clon recién hecho hace falta antes de `tsc`.

GitHub Actions corre exactamente eso en cada pull request y en cada push a `main`
([`.github/workflows/ci.yml`](.github/workflows/ci.yml)): un job para la API (PHP 8.4 con Postgres + pgvector) y otro para el web (Node 22).
Para que un PR no se pueda fusionar con el CI en rojo, actívalo como requisito en
Settings → Branches → Branch protection rules (esa regla se configura en GitHub, no en el repositorio).

## Widget en un sitio web

En la página de cada agente (Agentes → tu agente → «Instalar en tu sitio web») está el código para pegar:

```html
<script src="https://TU-DOMINIO/widget.js" data-agent="ag_XXXXXXXXXX" async></script>
```

- **Probarlo aquí mismo:** botón «Probar en esta página» en esa misma tarjeta.
- **Probarlo como sitio de terceros** (otro origen, que es lo que realmente importa):
  `python3 -m http.server 9000 --directory docs` y abrir
  `http://localhost:9000/widget-demo.html?agent=ag_XXXXXXXXXX`. Si el agente tiene **sitios permitidos**
  (ver más abajo), añade `localhost` a la lista; si no, el widget no aparecerá en esa página.
- El widget usa Shadow DOM (no choca con los estilos del sitio), no usa cookies y guarda un identificador
  de visitante y la conversación activa en `localStorage`.
- Posición (izquierda/derecha) y color se cambian desde el panel (planes Business y Pro). Un agente inactivo no muestra el widget.
- **Sitios permitidos:** en esa misma tarjeta se listan los dominios donde puede aparecer el agente (un dominio incluye sus
  subdominios; vacío = cualquiera). El widget compara la cabecera `Origin` del navegador: frena el uso casual desde otros
  sitios, **no es autenticación** (alguien fuera de un navegador puede falsear la cabecera; para eso están los límites de uso).
- API pública: `GET /api/widget/{agente}/config`, `POST …/messages`, `GET …/conversations/{id}/messages`
  (ver [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)).

## Leads y atención humana

Cada respuesta del agente trae, además del texto, dos decisiones (salida estructurada, un solo viaje al modelo):

- **`lead`**: cuando el cliente da su teléfono o correo, se crea un lead (Nuevo → Contactado → Calificado →
  Convertido / Perdido). Los datos siguientes se fusionan en el mismo lead, sin duplicar ni pisar el trabajo del equipo.
  Sin teléfono ni correo válidos no hay lead.
- **`action: request_human`**: si el cliente pide una persona, se queja o el agente no puede responder, la
  conversación pasa a **Esperando** y **la IA deja de contestar** hasta que alguien del equipo la tome.

Estados: `ai` → `waiting` → `human` → `closed` (en **Conversaciones**: tomar, responder, devolver a la IA, marcar como
resuelta). Al cliente le llega la respuesta de la persona (con su nombre de pila) a través del mismo widget; una
conversación cerrada no se reabre: si el cliente vuelve a escribir, empieza una nueva.

- **Avisos por correo** (`INBOX_NOTIFY_BY_EMAIL`, activado por defecto): al equipo completo cuando un cliente espera a una
  persona, y a propietario/administradores cuando entra un lead. Van por la cola: **sin worker no salen**. En desarrollo
  se escriben en `api/storage/logs/laravel.log`; en producción configura `MAIL_*` (SMTP/SES/…).
- Insignias en el menú (Conversaciones esperando, Leads nuevos) y alerta en el panel.
- **Límites conscientes:** el widget recibe respuestas por sondeo (cada 4 s con el panel abierto), no por WebSockets; si nadie
  toma una conversación en espera, la IA seguirá callada (no hay tiempo de espera ni derivación fuera de horario);
  no hay preferencias de aviso por persona ni exportación de leads.

## Planes, límites y facturación

Cada empresa tiene **una suscripción**. Al crearla empieza una **prueba de 14 días en Starter, sin tarjeta**
(`BILLING_TRIAL_DAYS`, `BILLING_TRIAL_PLAN`). Los planes viven en la tabla `plans` (se editan en `/admin/plans`):

| | Starter | Business | Pro |
|---|---|---|---|
| Precio / mes | $19 | $49 | $99 |
| Agentes activos | 1 | 3 | 10 |
| Mensajes / mes | 1 000 | 5 000 | 20 000 |
| Sitios permitidos (total) | 1 | 3 | 10 |
| Usuarios en el equipo | 1 | 3 | 10 |
| Bandeja con atención humana | — | ✓ | ✓ |
| Personalizar el widget | — | ✓ | ✓ |
| Analíticas | — | ✓ | ✓ |

- **Mensajes:** cuenta cada respuesta que el widget da a un visitante, por periodo de facturación (ciclo de Stripe, la prueba, o mes
  calendario para planes por cortesía). Al llegar al 100 % **el agente deja de contestar** y el visitante ve un mensaje amable sin
  botón de reintentar. Se avisa por correo a propietario y administradores al 80 % y al 100 % (una vez por periodo; necesita el worker).
  El chat de prueba del panel **no** cuenta contra el tope, pero sí se registra para medir costo.
- **Agentes:** el tope se aplica al activar/crear (402 `plan_limit`). Si una empresa baja de plan teniendo más agentes activos,
  solo atienden los más antiguos hasta el tope.
- **Fin de prueba:** el comando diario `billing:lifecycle` avisa por correo a propietario y administradores **3 días antes**
  (`BILLING_TRIAL_ENDING_DAYS`) y **cuando terminó** (una vez cada uno; solo durante la semana siguiente, para no escribirle de golpe a
  una empresa abandonada hace meses). Lo mismo para un plan por cortesía con fecha de fin. Extender la prueba desde `/admin`
  rehabilita los avisos. Corre a las 13:00 UTC y necesita el planificador y el worker.
- **Cobro fallido (`past_due`):** el servicio sigue mientras Stripe reintenta (`BILLING_PAST_DUE_KEEPS_SERVICE`). Cancelada,
  impaga o suspendida: el widget se oculta.
- **Stripe:** Checkout para contratar y Portal de facturación para cambiar de plan, tarjeta o cancelar (solo el propietario).
  El estado local se sincroniza **solo por webhooks** firmados e idempotentes. Configuración:
  1. `STRIPE_SECRET` y `STRIPE_WEBHOOK_SECRET` en `api/.env`.
  2. En Stripe, un endpoint de webhooks apuntando **directo a Laravel** (`https://TU-API/api/webhooks/stripe`, sin pasar por Next;
     la firma se valida sobre el cuerpo crudo) con los eventos `checkout.session.completed`, `customer.subscription.created|updated|deleted`
     e `invoice.payment_failed`.
  3. El **Price ID** de cada plan en `/admin/plans`.
- **Sin claves de Stripe todo lo demás funciona** (pruebas, planes por cortesía, límites): solo se desactiva el botón de pago.

### Equipo e invitaciones

En **Equipo** el propietario y los administradores invitan por correo. Roles: **propietario** (uno; cambia plan y roles),
**administrador** (gestiona agentes de IA, conocimiento y equipo; invita y quita agentes) y **agente** (ve el panel y atiende
conversaciones y leads). Nadie cambia su propio rol ni toca al propietario.

- **Plazas:** el tope sale del plan (1/3/10) y cuentan los miembros **más las invitaciones vigentes**. Una invitación vence a los 7 días
  (`TEAM_INVITATION_DAYS`) y entonces libera su plaza. Si una empresa baja de plan con más personas de las que caben, nadie pierde acceso:
  solo deja de poder invitar.
- **El enlace de la invitación viaja solo por correo** y no se muestra a quien invita: es lo que prueba que quien se une controla ese
  buzón (quien se registra desde el enlace queda con el correo verificado). Si el correo no sale, el panel lo avisa y se usa «Reenviar».
  En desarrollo el correo está en `api/storage/logs/laravel.log`. En la base solo queda la huella (sha256) del token.
- **Quien recibe el enlace:** si no tiene cuenta, la crea ahí mismo; si ya la tiene, inicia sesión con ese correo y acepta. Una cuenta
  solo puede pertenecer a **una empresa** (límite del MVP): si ya está en otra, debe usar otro correo.
- Quitar a alguien le retira el acceso al instante y las conversaciones que tenía tomadas vuelven a «esperando».
- Hay un tope de 30 invitaciones por empresa y hora (`TEAM_INVITATIONS_PER_HOUR`) para que la app no sirva de emisor de correo.

### Analíticas (Business y Pro)

Cualquier miembro las ve en **Analíticas** (7, 30 o 90 días, comparado con el período anterior y en la zona horaria de la empresa):
conversaciones, leads y qué porcentaje de conversaciones dejó un contacto, cuántas pidieron una persona, cuántas resolvió solo la IA,
**mediana del tiempo de primera respuesta humana**, mensajes por conversación, actividad por día y **por hora del día**, desglose por
agente y las **preguntas que el agente contestó sin encontrar nada en la base de conocimiento** (lo que conviene cargar).

Definiciones que conviene tener presentes: «pidieron una persona» cuenta aunque la conversación se devolviera luego a la IA (se guarda
la marca aparte, `handoff_requested_at`); la lista de preguntas sin respuesta incluye saludos y charla, y solo revisa agentes con una
base de conocimiento asignada. Los datos son los que ya se guardan: no hay seguimiento de visitas a la página ni de conversiones fuera del chat.

### Panel de plataforma (superadmin)

`/admin` es solo para el equipo de Hamster: resumen del mes (**MRR − costo de IA − infraestructura = margen**), consumo por
modelo/empresa, empresas (conceder plan por cortesía, extender prueba, suspender), planes, usuarios, auditoría y ajustes. Quien no sea
superadmin recibe 404. En desarrollo `demo@hamster.test` ya lo es; para promover una cuenta real:

```bash
php artisan admin:promote correo@dominio.com
```

El costo de IA se estima con los precios de `config/ai.php` y los tokens que reporta cada respuesta; el MRR se calcula con el precio
de lista de cada plan activo (no con facturas reales de Stripe). `PLATFORM_INFRA_COST_USD` fija el costo de infraestructura mensual.

## Proveedores de IA

Por defecto todo funciona con simuladores (`mock`), sin claves. Para usar modelos reales, en `api/.env`:

```bash
AI_PROVIDER=anthropic              # chat con Claude (SDK oficial de Anthropic)
ANTHROPIC_API_KEY=sk-ant-…
ANTHROPIC_MODEL=claude-opus-5-5    # ver nota de costos

AI_EMBEDDINGS_PROVIDER=openai      # Anthropic no ofrece embeddings
OPENAI_API_KEY=sk-…
```

Al cambiar de proveedor de embeddings los vectores anteriores dejan de ser comparables: la búsqueda los ignora
hasta que se regeneran con `php artisan knowledge:reindex` (`--stale` solo los desactualizados,
`--organization=ID` para una empresa). Necesita el worker de colas en marcha.

**Costos:** el modelo por defecto es el más capaz (y el más caro). Para un chat de atención al cliente con RAG
suele bastar uno más económico (`claude-sonnet-5-5`, `claude-haiku-4-5`); medir el costo real por conversación
antes de fijar los precios de los planes es decisión tuya.

**Estos proveedores están verificados solo con respuestas simuladas de la API** (tests con transporte HTTP falso),
no contra los servicios reales: ninguna clave estuvo disponible. Al conectar la primera clave conviene probar
una conversación completa y subir un documento.

## Producción: lo que no es opcional

- **`TRUSTED_PROXIES`**: los límites de uso del widget dependen de ver la IP real del visitante. Detrás de
  nginx/Cloudflare/Next, poner las IPs de esos proxies (nunca `*` si la API es accesible directo).
- **API del widget directa:** con `NEXT_PUBLIC_WIDGET_API_URL` el snippet incluye `data-api` y el widget habla
  con Laravel sin pasar por Next (Laravel ve la IP real y se evita un salto).
- **Tiempo de espera:** una respuesta de IA puede tardar hasta ~40 s; PHP-FPM/nginx deben permitir ≥ 60 s en `/api/widget/*`.
- **Sitios permitidos por agente:** el filtro por `Origin` frena el uso casual desde sitios no autorizados, pero un cliente fuera de
  un navegador puede falsearlo. La defensa real contra el gasto de créditos son los límites por visitante (8/min), por IP
  (30/min y 200/h por agente), por conversación (100 mensajes) y el tope mensual del plan.
- **Caché del widget:** `GET …/config` se cachea hasta 60 s; un cambio de dominios, plan o suspensión puede tardar ese tiempo en reflejarse.
- **Stripe:** webhook directo a Laravel (ver «Planes, límites y facturación»); sin él las suscripciones no se sincronizan.
- **Planificador y worker:** el cron `* * * * * php artisan schedule:run` y un worker de colas (`queue:work`) son necesarios para los avisos de
  fin de prueba, de cupo, de leads y de conversaciones esperando.
- **Correo saliente:** las invitaciones al equipo dependen de `MAIL_*` bien configurado (no pasan por la cola, para no guardar el token ahí).

## Estado del producto

| Sprint | Alcance | Estado |
|---|---|---|
| 0 | Andamiaje, auth (US-001–005), empresa (US-010–012), base multi-tenant, interfaz `AIProvider` + mock | ✅ |
| 1 | Agentes (AI-001–009) y base de conocimiento (KB-001–009) con pgvector, chat de prueba con RAG | ✅ |
| 2 | Widget instalable (`/widget.js`), chat público con RAG, historial de conversaciones, proveedores reales (Anthropic · OpenAI embeddings) | ✅ |
| 3 | Captura de leads, bandeja con intervención humana (IA → persona), avisos por correo, panel con datos reales | ✅ |
| 4 | Planes y límites de mensajes, suscripciones (Stripe), panel superadmin, sitios permitidos por agente | ✅ |
| 5 | Equipo e invitaciones con tope de usuarios por plan, analíticas (Business+), avisos de fin de prueba con planificador | ✅ |

Limitaciones conscientes por ahora:

- **Los mocks siguen siendo el valor por defecto.** `MockEmbeddingProvider` hace hash de palabras: encuentra textos
  con palabras en común pero no entiende sinónimos. Los proveedores reales existen (Anthropic, OpenAI embeddings)
  pero no se han probado con claves reales. Gemini y OpenAI para chat no están implementados.
- **Redis:** las colas usan la base de datos.
- **Documentos:** PDF (con texto), TXT y MD. DOCX, importar un sitio web, Drive y Notion (KB-010 a KB-012)
  están fuera del MVP. Para actualizar un archivo se borra y se vuelve a subir; el texto y las FAQ sí se editan.
- **Stripe verificado solo con eventos firmados simulados** y un cliente HTTP falso: no hubo claves reales. Antes de cobrar,
  haz una compra de prueba en modo test (Checkout → webhook → plan activo).
- **Lo que los planes todavía no ofrecen:** acceso por API, integraciones y soporte prioritario (Pro, en el plan original) no están
  construidos ni se mencionan en las tarjetas de precios. Suspender una empresa oculta el widget y bloquea crear agentes, pero no impide
  el resto de ediciones en el panel. Una cuenta pertenece a una sola empresa.
