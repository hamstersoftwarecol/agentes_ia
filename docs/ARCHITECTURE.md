# Hamster AI Agents — Arquitectura

SaaS multi-tenant para que una empresa cree agentes de IA con su propio conocimiento (RAG),
los instale en su web, capture leads e intervenga manualmente en las conversaciones.

```
web/  Next.js (App Router, TypeScript, Tailwind)  → UI, dashboard, panel de plataforma, widget.js
api/  Laravel 12 (Sanctum, Breeze API)            → Auth, empresas, agentes, leads, billing
DB    PostgreSQL 16 (+ pgvector desde el Sprint de RAG)
```

El navegador solo habla con `web` (mismo origen). `next.config.ts` reescribe `/api/*` y
`/sanctum/*` hacia Laravel, así la sesión de Sanctum por cookie funciona sin CORS.
En producción se resuelve igual con un reverse proxy (`app.dominio.com/api` → Laravel).

## Multi-tenancy

- Una cuenta (`users`) pertenece a una o más empresas (`organizations`) vía `organization_user`
  (`role`: owner | admin | agent). El MVP permite una sola empresa por cuenta, pero el modelo no lo asume.
- Toda tabla de negocio (agentes, conocimiento, conversaciones, leads…) lleva `organization_id`
  y el modelo usa el trait `App\Models\Concerns\BelongsToOrganization`, que aplica un global scope
  con la empresa actual (`App\Support\CurrentOrganization`) y la asigna al crear.
- La empresa actual se resuelve en el middleware `ResolveCurrentOrganization` a partir del usuario
  autenticado. Sin empresa actual, el scope devuelve **cero filas** (falla cerrado, nunca todo).

## Agentes y base de conocimiento (RAG)

```
Documento (texto · FAQ · PDF/TXT/MD)
   │  POST /api/knowledge-bases/{id}/documents      → estado "pending", responde 202
   ▼
Job ProcessDocument (cola)
   extraer texto → TextChunker (~1000 car., solape 150) → EmbeddingProvider → document_chunks
   │                                                       (vector(1536) + índice HNSW coseno)
   ▼
Documento "ready"  ──►  KnowledgeSearch (distancia coseno, solo documentos "ready")
                              │
Cliente: «¿cuánto cuesta?» ───┤  top 5 fragmentos
                              ▼
            AgentPromptBuilder (rol, tono, idioma, reglas, datos y horario de la empresa,
                                <conocimiento>…</conocimiento>)  →  AIProvider  →  respuesta + fuentes
```

- Una base de conocimiento puede ser usada por varios agentes; un agente usa una (o ninguna).
- Cada FAQ es un documento (pregunta = título, respuesta = contenido), así se edita y borra por separado.
- Editar el contenido de un documento (o la pregunta de una FAQ) lo reprocesa y reemplaza sus fragmentos.
- Los archivos viven en el disco privado `local` (`storage/app/private`), nunca en `public`.
- Los vectores no se serializan en ninguna respuesta de la API.
- Un job no tiene usuario ni petición: toma la empresa del propio documento antes de tocar datos del tenant.
- El prompt trata el texto de `<conocimiento>` como datos, no instrucciones (mitiga prompt injection desde documentos).
- Probar un agente (`POST /api/agents/{id}/test-chat`) no guarda la conversación; solo registra el consumo (`source=test`).
- `config/ai.php → embeddings.dimensions` (1536) debe coincidir con la columna `vector(1536)`; cambiarla exige
  migración y reindexar. Cada fragmento guarda `embedding_model` para poder migrar de proveedor.

## Widget y API pública

```
Sitio del cliente ── <script src=…/widget.js data-agent=ag_…> ──► Shadow DOM, sin cookies
        │  GET  /api/widget/{agente}/config                     (público, cacheable 60 s)
        │  POST /api/widget/{agente}/messages   X-Visitor-Id    (síncrono: RAG + modelo)
        │  GET  /api/widget/{agente}/conversations/{uuid}/messages
        ▼
routes/widget.php  (grupo propio: SIN sesión de Sanctum, SIN CSRF; solo SubstituteBindings + throttle)
```

- **Identidad del visitante:** `X-Visitor-Id` es un UUID aleatorio generado en su navegador; funciona como
  secreto para releer su conversación. Una conversación solo es accesible para el visitante que la inició y a
  través del mismo agente; en cualquier otro caso la respuesta es 404 (no 403, para no confirmar que existe).
- **Tenant sin usuario:** el agente se resuelve por `public_id` y fija la empresa actual durante el binding de la
  ruta (`AppServiceProvider::registerWidget`), así la conversación y el conocimiento ya salen acotados.
- **El historial sale de la BD**, nunca del cliente: un visitante no puede inventar mensajes "del agente".
  Al modelo se le envían los últimos 20 mensajes (`config/widget.php`).
- **Atomicidad:** primero se llama al modelo y después se guarda todo en una transacción. Si la IA falla, no queda
  un mensaje huérfano, el visitante recibe un 503 amable (el error técnico solo va al log) y puede reintentar.
- **CORS** abierto únicamente para `api/widget/*` y sin credenciales (`config/cors.php`); el resto de la API es de
  mismo origen y no se abre a otros sitios.
- **Límites** (`widget-messages`): 8/min por visitante, 30/min por IP+agente, 200/h por IP; tope de 100 mensajes
  por conversación; mensajes de hasta 1000 caracteres. La IP real exige `TRUSTED_PROXIES` correcto.
- **Privacidad:** de la URL de la página solo se guarda origen + ruta (sin query ni fragmento, que pueden traer
  datos personales o tokens). Al visitante nunca se le devuelve modelo, tokens ni fuentes.
- **XSS:** el widget y el panel muestran el contenido de los mensajes solo como texto. El color del widget se
  valida como `#RRGGBB` en el servidor y otra vez en el widget porque acaba dentro de CSS.
- **Fechas:** Laravel guarda en UTC y la conexión pgsql fija `timezone=UTC`; sin eso los `DEFAULT CURRENT_TIMESTAMP`
  usan la zona del servidor de BD y quedan desfasados.
- Al borrar un agente se conserva su historial (`agent_id` null + `agent_name` guardado).

## Turno del agente, leads y atención humana

```
mensaje del visitante ──► WidgetChat
   estado ai ──► AgentChat ──► modelo (salida estructurada: {reply, action, lead})
                    │            AgentTurnParser: lee el JSON de forma defensiva y sanea el lead
                    ├─ reply ───────────► se guarda y se devuelve al visitante
                    ├─ action=request_human ► ConversationWorkflow: ai → waiting  (+ correo al equipo)
                    └─ lead ────────────► LeadCapture: crea o fusiona (1 por conversación) (+ correo si es nuevo)
   estado waiting/human ──► solo se guarda el mensaje; la IA no contesta
   estado closed ──────────► se abre una conversación nueva
equipo (panel) ──► take / reply / release / close  ──► el visitante lo recibe por sondeo (?after=id)
```

- **Por qué salida estructurada y no herramientas:** un solo mensaje del modelo trae respuesta y acciones; con
  herramientas habría que devolverle el resultado y reenviar sus bloques de razonamiento firmados. Si el proveedor
  no soporta JSON o devuelve texto plano, se usa tal cual y no se actúa (degrada sin romper).
- **Los datos del lead son texto de un visitante anónimo**: teléfono de 7–15 dígitos, correo validado, nombre e interés
  sin caracteres de control y recortados. Un `null` nuevo nunca borra un dato ya conocido.
- **Quién atiende:** `ConversationWorkflow` concentra las transiciones (y las inválidas responden 409). Tomar o
  responder es trabajo de todo el equipo (`respond`); borrar leads, solo de propietario/administradores.
- **Notificaciones en cola y tenant:** un worker no tiene empresa actual y los modelos de negocio fallarían cerrados
  (relaciones vacías). Las notificaciones restauran el contexto desde su modelo (`RestoresTenant`); hay tests que
  serializan la notificación y la arman sin empresa fijada, como lo hace el worker.
- **Panel:** el resumen agrupa por día **en la zona horaria de la empresa**; `/api/dashboard/alerts` es el contador
  barato que consulta el menú cada pocos segundos.

### Orden de middleware y tenancy

`ResolveCurrentOrganization` se ejecuta **antes** de `SubstituteBindings` (`bootstrap/app.php`): los modelos
de negocio se resuelven con el global scope del tenant, así que si la empresa no está fijada antes del binding,
`GET /api/agents/{id}` devolvería 404 incluso para recursos propios. Hay tests de regresión.
Validaciones `Rule::exists()` no pasan por el global scope: filtran `organization_id` explícitamente.

## Planes, límites y facturación

```
plans (3, sembrados en la migración)      subscriptions (1 por empresa)         usage_records (1 fila por llamada a IA)
  límites: agentes, mensajes, sitios,  ◄─── plan_id, status, source ───►        type chat|embedding · source widget|test|document
  features: human_inbox, widget_custom.      stripe_*_id, periodo, trial_ends_at  messages (solo widget) · tokens · cost_micros
```

- **Una sola puerta de decisión:** `BillingGate::for($org)` devuelve un `Entitlements` inmutable con el estado efectivo
  (`active`, `trialing`, `past_due`, `expired`, `canceled`, `unpaid`, `incomplete`, `suspended`, `none`), el periodo de consumo y los
  límites del plan. Todo lo demás (widget, panel, controladores) le pregunta a `Entitlements` (`serves()`, `maxAgents()`,
  `feature()`, `quotaExceeded()`), nunca mira `subscriptions` directamente.
- **Periodo de consumo:** el de Stripe si lo hay; la ventana de la prueba; o el mes calendario para planes por cortesía
  (`source=manual`, sin periodo de Stripe). `past_due` conserva el servicio por configuración mientras Stripe reintenta.
- **Un solo veredicto para el widget:** `WidgetAvailability::blockedReason()` ordena los motivos (`inactive`, `unavailable`,
  `plan_limit`, `origin`, `quota`). La config pública responde `{active:false}` (con `reason:"origin"` solo ahí, para que el widget
  se oculte); los mensajes responden 403 con código (`quota_exceeded`, `unavailable`, `origin_not_allowed`). El widget **no reintenta**
  esos códigos. Si una empresa baja de plan y tiene más agentes activos que el tope, `agentServes()` deja atender solo a los más antiguos.
- **Cupo en el orden correcto:** `assertCanAnswer` **antes** de llamar al modelo (no se gasta IA que no se va a servir); el uso se
  registra dentro de la misma transacción que guarda la respuesta; después se evalúan los avisos (`QuotaAlerts`, 80 % y 100 %, una vez por
  periodo mediante `quota_alert_*_at`). Solo `source=widget` suma a `messages`; probar y procesar documentos cuestan pero no gastan cupo.
- **Costo:** `AiPricing` convierte tokens a micro-USD con la tabla de `config/ai.php`; un modelo desconocido o el mock cuestan 0.
- **Sitios permitidos:** `agents.allowed_domains` (jsonb, ≤10 por agente, tope de sitios en total por plan). Sin cabecera `Origin` se
  permite (mismo origen o fuera del navegador), el host del propio panel siempre se permite y un dominio incluye sus subdominios.
  Es un filtro contra el uso casual, **no autenticación**: `Origin` lo falsea cualquiera fuera de un navegador.
- **Funciones por plan:** `human_inbox` (tomar/responder/devolver; cerrar siempre se permite) y `widget_customization`. Sin la bandeja,
  `AgentPromptBuilder` cambia el prompt y `AgentChat` neutraliza `request_human`, para no prometer una persona que no existe.

### Stripe

- `StripeGateway` es una interfaz (`StripeSdkGateway` con el SDK oficial); los tests usan un cliente HTTP falso. La empresa viaja en
  `client_reference_id` y en `metadata` de Checkout.
- **Webhook** (`POST /api/webhooks/stripe`, fuera de `auth`, sin Next por medio): firma sobre el **cuerpo crudo**; idempotente por
  `stripe_events.event_id`; ignora eventos más viejos que `stripe_synced_at` (llegan desordenados); resuelve la empresa por
  metadata → id de suscripción → id de cliente. En la API de Stripe vigente el periodo se lee de los **items** de la suscripción.
- El panel nunca decide el plan por su cuenta: tras volver de Checkout muestra lo que los webhooks ya sincronizaron.

### Panel de plataforma

- `/api/admin/*` exige `is_superadmin` y responde **404** a cualquier otro (no revela que existe). Los superadmins no tienen empresa
  actual: el código de administración usa `withoutGlobalScopes()` de forma explícita, nunca el scope del tenant.
- Toda acción que cambia estado (conceder plan, extender prueba, suspender, editar un plan) queda en `audit_logs` con quién, cuándo y
  el cambio. Los ajustes que expone nunca incluyen secretos.
- Resumen: MRR (precio de lista de las suscripciones activas) − costo de IA del mes (`usage_records`) − `PLATFORM_INFRA_COST_USD`.

### Equipo e invitaciones

```
Quien invita ──POST /api/team/invitations──► TeamService::invite ──► correo con el enlace (token en claro SOLO aquí)
                                                │ organization_invitations: token_hash = sha256(token), vence en 7 días
Quien recibe ──GET  /api/invitations/{token}──► vista previa pública (empresa, rol, ¿ya tiene cuenta?)
             ├─POST /api/invitations/{token}/register ─► crea la cuenta (correo verificado) + la une + inicia sesión
             └─POST /api/invitations/{token}/accept ───► (con sesión y el mismo correo) la une
```

- **Reglas de rol en un solo sitio:** `OrganizationRole::canAssign($objetivo)` (propietario → admin/agente; admin → agente; agente → nadie;
  nadie → propietario) lo usan invitar, cambiar rol, quitar y revocar. Ni uno mismo.
- **Plazas = miembros + invitaciones vigentes ≤ `plans.max_users`.** `Entitlements::maxUsers()` no depende de que el plan esté al día. Invitar
  y aceptar toman un `lockForUpdate` sobre la fila de la empresa, así dos invitaciones simultáneas no superan el tope; aceptar vuelve a
  comprobarlo (si el plan bajó entre tanto → 402 y, al registrarse, la cuenta ni se crea).
- **El token solo viaja por correo.** La API no lo devuelve a quien invita: el registro por invitación deja el correo verificado y eso solo
  es sensato si el token prueba el control del buzón; si lo viera quien invita podría crear cuentas verificadas para correos que no controla.
  Por eso tampoco se encola la notificación (el token quedaría en la tabla de trabajos). Si el correo falla, `emailed: false` y se reenvía.
- **Una cuenta, una empresa** (límite del MVP, igual que al crear una empresa): aceptar con una cuenta que ya pertenece a otra → 409.
- **Quitar a un miembro** desvincula, limpia su `current_organization_id` y devuelve a «esperando» sus conversaciones tomadas.
- `?next=` en el login (solo rutas internas, ver `safeNext`) lleva de vuelta a la invitación; `RouteGuard` de invitados lo respeta.
- Throttle: `team-invitations` por empresa (la clave sale del usuario, no de `CurrentOrganization`: el *throttle* corre antes que el
  middleware de empresa) y `invitation-lookup` por IP para las rutas públicas.

### Analíticas

`AnalyticsReport` calcula, para una ventana de días calendario **en la zona horaria de la empresa** y la ventana anterior, totales, tasas,
serie diaria, distribución por hora, desglose por agente y «preguntas sin respuesta» (respuestas del asistente con `sources` vacío cuyo agente
tiene base de conocimiento; la pregunta es el mensaje del visitante que la precede, por `lateral join`). Gate: función `analytics` del plan.

- `conversations.handoff_requested_at` es la marca **permanente** de «alguien pidió una persona»: `release()` borra `handoff_reason` y
  `handoff_at`, así que sin ella la tasa de derivación se subestimaría. La migración la rellena para las conversaciones existentes.
- «Primera respuesta humana» = mediana (`percentile_cont`) entre `handoff_requested_at` y el primer mensaje `human` posterior. Una
  intervención del equipo que nadie pidió no cuenta como tiempo de respuesta.

### Ciclo de vida de la suscripción

`SubscriptionLifecycle` (comando `billing:lifecycle`, a diario a las 13:00 UTC): para suscripciones `trial` (columna `trial_ends_at`) y
`manual` con fecha de fin (`current_period_end`) envía **una** vez «termina pronto» (`billing.trial_ending_days`) y **una** vez «terminó»
(solo si terminó en los últimos 7 días). Idempotente con `ending_notified_at`/`ended_notified_at`; `extendTrial` y `grant` las limpian.
Las de Stripe no pasan por aquí. Usa `StaffNotifier::billing` (propietario y administradores).

### Tests y trampas de este módulo

- Los helpers compartidos viven en `tests/Pest.php` (cada archivo debe poder ejecutarse solo). `resetRequestState()` limpia los
  singletons con alcance de petición y los controladores cacheados por la ruta cuando un test hace varias peticiones.
- Las factories de empresa crean una suscripción Pro por cortesía; `setPlan()` la cambia para probar un límite concreto.
- `sum()` en PostgreSQL devuelve texto (convertir a entero); en consultas con *join* calificar `usage_records.created_at`.
- Al sembrar datos a mano en SQL usar `now() at time zone 'utc'`: `now()` a secas usa la zona del servidor y desfasa los periodos.
- Equipo/analíticas: el *throttle* se evalúa antes que el middleware de empresa (orden de prioridad de Laravel); una clave basada en
  `CurrentOrganization` funciona en tests (estado residual entre peticiones) y falla en producción. Los tests de límites llaman
  `resetRequestState()` entre peticiones para simular peticiones independientes.
- El middleware `verified` responde **409** (no 403) a peticiones JSON. `report()` es un helper global de Laravel: no usarlo de nombre en tests.
- Un test que hace `actingAs()` deja la sesión puesta en la misma app: una comprobación «exige sesión» va en su propio test.
- Los enlaces de invitación se leen en tests con `Notification::sent(new AnonymousNotifiable, TeamInvitation::class)` (`invitationUrls()` en Pest.php).

## Proveedores de IA

`App\Contracts\AIProvider` es la interfaz; hay un `MockAIProvider` para desarrollar sin API keys.
Los proveedores reales (OpenAI, Anthropic, Gemini) se enchufan en `config/ai.php` sin tocar el dominio.
Chat (`AIProvider`) y embeddings (`EmbeddingProvider`) se configuran por separado: Anthropic no ofrece
embeddings propios, así que el RAG usa un proveedor de embeddings aparte. Por defecto ambos son mocks; los reales
(Anthropic para chat, OpenAI para embeddings) están verificados solo con transporte HTTP falso.

## Desarrollo local

Ver `README.md`.
