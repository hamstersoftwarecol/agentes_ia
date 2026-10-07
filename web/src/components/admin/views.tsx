"use client";

import Link from "next/link";
import { useState } from "react";
import { Pager, Table, Td } from "@/components/admin/Table";
import { Alert, Badge, Card, Input, Select, Spinner } from "@/components/ui";
import { formatDateTime, formatMicros, formatNumber } from "@/lib/format";
import type { AdminSettings, AdminUsage, AdminUser, AuditEntry, Paginated } from "@/lib/types";
import { useApi } from "@/lib/use-api";

const ACTIONS: Record<string, string> = {
  "subscription.granted": "Plan por cortesía asignado",
  "subscription.trial_extended": "Prueba extendida",
  "subscription.synced": "Suscripción sincronizada con Stripe",
  "organization.suspended": "Empresa suspendida",
  "organization.unsuspended": "Empresa reactivada",
  "plan.updated": "Plan editado",
  "stripe.payment_failed": "Cobro fallido",
  "stripe.unknown_price": "Precio de Stripe sin plan asociado",
};

function summarize(metadata: Record<string, unknown> | null) {
  if (!metadata) return "";
  return Object.entries(metadata)
    .filter(([, value]) => value !== null && value !== undefined)
    .map(([key, value]) => `${key}: ${typeof value === "object" ? JSON.stringify(value) : String(value)}`)
    .join(" · ");
}

export function AuditList({ rows, withOrganization = true }: { rows: AuditEntry[]; withOrganization?: boolean }) {
  if (rows.length === 0) return <p className="text-sm text-muted">Todavía no hay acciones registradas.</p>;
  return (
    <Table head={["Cuándo", "Acción", "Quién", ...(withOrganization ? ["Empresa"] : []), "Detalle"]}>
      {rows.map((row) => (
        <tr key={row.id}>
          <Td className="whitespace-nowrap">{formatDateTime(row.created_at)}</Td>
          <Td>{ACTIONS[row.action] ?? row.action}</Td>
          <Td>{row.actor ?? <span className="text-muted">Sistema</span>}</Td>
          {withOrganization && (
            <Td>
              {row.organization_id ? (
                <Link href={`/admin/organizations/${row.organization_id}`} className="hover:underline">
                  {row.organization ?? `#${row.organization_id}`}
                </Link>
              ) : (
                "—"
              )}
            </Td>
          )}
          <Td className="max-w-[22rem] break-words text-xs text-muted">{summarize(row.metadata)}</Td>
        </tr>
      ))}
    </Table>
  );
}

export function AuditView() {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState("");
  const logs = useApi<Paginated<AuditEntry>>(`/admin/audit-logs?page=${page}${action ? `&action=${action}` : ""}`);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">Auditoría</h1>
      <p className="text-sm text-muted">Quién hizo qué sobre cuentas y facturación: acciones del equipo de plataforma y eventos de cobro.</p>
      <div className="max-w-xs">
        <Select
          label="Tipo"
          value={action}
          onChange={(e) => {
            setAction(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Todas</option>
          <option value="subscription.">Suscripciones</option>
          <option value="organization.">Suspensiones</option>
          <option value="plan.">Planes</option>
          <option value="stripe.">Eventos de Stripe</option>
        </Select>
      </div>
      {logs.loading && <Spinner className="size-5" />}
      {logs.error && <Alert>{logs.error.message}</Alert>}
      {logs.data && (
        <>
          <AuditList rows={logs.data.data} />
          <Pager page={page} last={logs.data.meta.last_page} onPage={setPage} />
        </>
      )}
    </div>
  );
}

export function UsersView() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const users = useApi<Paginated<AdminUser>>(`/admin/users?page=${page}${search ? `&search=${encodeURIComponent(search)}` : ""}`);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">Usuarios</h1>
      <div className="max-w-md">
        <Input
          label="Buscar por nombre o correo"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
      </div>
      {users.loading && <Spinner className="size-5" />}
      {users.error && <Alert>{users.error.message}</Alert>}
      {users.data && (
        <>
          <Table head={["Usuario", "Empresas", "Correo", "Alta"]}>
            {users.data.data.map((u) => (
              <tr key={u.id}>
                <Td>
                  <p className="font-medium">
                    {u.name} {u.is_superadmin && <Badge tone="info">superadmin</Badge>}
                  </p>
                  <p className="text-xs text-muted">{u.email}</p>
                </Td>
                <Td>
                  {u.organizations.length === 0 && <span className="text-muted">—</span>}
                  {u.organizations.map((o) => (
                    <p key={o.id}>
                      <Link href={`/admin/organizations/${o.id}`} className="hover:underline">
                        {o.name}
                      </Link>{" "}
                      <span className="text-xs text-muted">({o.role})</span>
                    </p>
                  ))}
                </Td>
                <Td>{u.verified ? <Badge tone="success">Verificado</Badge> : <Badge>Sin verificar</Badge>}</Td>
                <Td>{formatDateTime(u.created_at)}</Td>
              </tr>
            ))}
          </Table>
          <Pager page={page} last={users.data.meta.last_page} onPage={setPage} />
        </>
      )}
    </div>
  );
}

export function UsageView() {
  const [days, setDays] = useState("30");
  const usage = useApi<AdminUsage>(`/admin/usage?days=${days}`);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">Uso de IA</h1>
      <p className="text-sm text-muted">De dónde viene el costo de IA. Cifras estimadas con los precios de <code>config/ai.php</code>.</p>
      <div className="max-w-xs">
        <Select label="Período" value={days} onChange={(e) => setDays(e.target.value)}>
          <option value="7">Últimos 7 días</option>
          <option value="30">Últimos 30 días</option>
          <option value="90">Últimos 90 días</option>
        </Select>
      </div>

      {usage.loading && <Spinner className="size-5" />}
      {usage.error && <Alert>{usage.error.message}</Alert>}
      {usage.data && (
        <>
          <Card className="space-y-3">
            <h2 className="font-semibold">Por modelo</h2>
            <Table head={["Modelo", "Tipo", ">Llamadas", ">Tokens entrada", ">Tokens salida", ">Costo"]} className="border-0">
              {usage.data.by_model.map((r) => (
                <tr key={`${r.model}/${r.type}`}>
                  <Td className="font-mono text-xs">{r.model}</Td>
                  <Td>{r.type === "chat" ? "Chat" : "Embeddings"}</Td>
                  <Td right>{formatNumber(r.calls)}</Td>
                  <Td right>{formatNumber(r.input_tokens)}</Td>
                  <Td right>{formatNumber(r.output_tokens)}</Td>
                  <Td right>{formatMicros(r.cost_micros)}</Td>
                </tr>
              ))}
            </Table>
            {usage.data.by_model.length === 0 && <p className="text-sm text-muted">Sin uso en este período.</p>}
          </Card>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card className="space-y-3">
              <h2 className="font-semibold">Empresas que más cuestan</h2>
              <Table head={["Empresa", ">Mensajes", ">Costo"]} className="border-0">
                {usage.data.by_organization.map((r) => (
                  <tr key={r.id}>
                    <Td>
                      <Link href={`/admin/organizations/${r.id}`} className="hover:underline">
                        {r.name}
                      </Link>
                    </Td>
                    <Td right>{formatNumber(r.messages)}</Td>
                    <Td right>{formatMicros(r.cost_micros)}</Td>
                  </tr>
                ))}
              </Table>
            </Card>

            <Card className="space-y-3">
              <h2 className="font-semibold">Por origen</h2>
              <Table head={["Origen", ">Llamadas", ">Costo"]} className="border-0">
                {usage.data.by_source.map((r) => (
                  <tr key={r.source}>
                    <Td>{{ widget: "Clientes (widget)", test: "Pruebas del panel", document: "Documentos (embeddings)" }[r.source] ?? r.source}</Td>
                    <Td right>{formatNumber(r.calls)}</Td>
                    <Td right>{formatMicros(r.cost_micros)}</Td>
                  </tr>
                ))}
              </Table>
            </Card>
          </div>
        </>
      )}
    </div>
  );
}

export function SettingsView() {
  const settings = useApi<AdminSettings>("/admin/settings");
  const s = settings.data;
  if (settings.error) return <Alert>{settings.error.message}</Alert>;
  if (!s) return <Spinner className="size-5" />;

  const flag = (set: boolean) => <Badge tone={set ? "success" : "danger"}>{set ? "Configurada" : "Falta"}</Badge>;

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">Configuración</h1>
      <Alert tone="info">Solo lectura: los proveedores y claves se configuran en el entorno del servidor (<code>.env</code>). Nunca se muestran los secretos.</Alert>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h2 className="font-semibold">Proveedores de IA</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Line label="Chat" value={s.ai.chat_provider} />
            <Line label="Embeddings" value={s.ai.embeddings_provider} />
            <Line label="Anthropic" value={<>{flag(s.ai.anthropic.key_set)} <span className="font-mono text-xs">{s.ai.anthropic.model} · esfuerzo {s.ai.anthropic.effort}</span></>} />
            <Line label="OpenAI" value={<>{flag(s.ai.openai.key_set)} <span className="font-mono text-xs">{s.ai.openai.embedding_model}</span></>} />
          </dl>
          {s.ai.chat_provider === "mock" && <p className="mt-3 text-xs text-muted">El chat usa el proveedor simulado: no hay costo real ni respuestas inteligentes.</p>}
        </Card>

        <Card>
          <h2 className="font-semibold">Facturación</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Line label="Stripe (clave secreta)" value={flag(s.billing.stripe_secret_set)} />
            <Line label="Stripe (webhook)" value={flag(s.billing.stripe_webhook_secret_set)} />
            <Line label="Prueba gratuita" value={`${s.billing.trial_days} días · plan ${s.billing.trial_plan}`} />
            <Line label="Infraestructura" value={`US$ ${s.billing.infrastructure_cost_usd} al mes`} />
          </dl>
        </Card>

        <Card>
          <h2 className="font-semibold">Entorno</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Line label="Ambiente" value={s.runtime.environment} />
            <Line label="Cola" value={s.runtime.queue} />
            <Line label="Correo" value={s.runtime.mail} />
          </dl>
        </Card>

        <Card>
          <h2 className="font-semibold">Precios de IA (USD por millón de tokens)</h2>
          <Table head={["Modelo", ">Entrada", ">Salida"]} className="mt-3 border-0">
            {Object.entries(s.ai.pricing).map(([model, price]) => (
              <tr key={model}>
                <Td className="font-mono text-xs">{model}</Td>
                <Td right>{price.input.toFixed(2)}</Td>
                <Td right>{price.output.toFixed(2)}</Td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>
    </div>
  );
}

function Line({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <dt className="text-muted">{label}</dt>
      <dd className="flex items-center gap-2 text-right font-medium">{value}</dd>
    </div>
  );
}
