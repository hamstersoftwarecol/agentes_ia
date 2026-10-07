"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { AuditList } from "@/components/admin/views";
import { Alert, Badge, Button, Card, Input, Select, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { formatDateTime, formatMicros, formatNumber } from "@/lib/format";
import type { AdminOrganizationDetail, AdminPlan } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";
import { STATE_LABEL } from "../organizations-view";

export function OrganizationDetailView() {
  const { id } = useParams<{ id: string }>();
  const org = useApi<AdminOrganizationDetail>(`/admin/organizations/${id}`);
  const plans = useApi<AdminPlan[]>("/admin/plans");

  if (org.loading) return <Spinner className="size-5" />;
  if (org.error || !org.data) return <Alert>{org.error?.status === 404 ? "No existe esa empresa." : (org.error?.message ?? "Error")}</Alert>;
  const o = org.data;
  const state = STATE_LABEL[o.state];

  return (
    <div className="space-y-6">
      <div>
        <Link href="/admin/organizations" className="text-sm text-muted hover:underline">
          ← Empresas
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{o.name}</h1>
          <Badge tone={state.tone}>{state.label}</Badge>
          {o.plan && <Badge tone="info">{o.plan.name}</Badge>}
        </div>
        <p className="mt-1 text-sm text-muted">
          {o.owner ? `${o.owner.name} · ${o.owner.email}` : "Sin propietario"} · creada {formatDateTime(o.created_at)}
        </p>
      </div>

      {o.suspended && <Alert>Suspendida: {o.suspension_reason}</Alert>}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h2 className="font-semibold">Suscripción</h2>
          {o.subscription ? (
            <dl className="mt-3 space-y-1.5 text-sm">
              <Row label="Origen" value={o.subscription.source === "stripe" ? "Stripe" : o.subscription.source === "manual" ? "Cortesía" : "Prueba gratuita"} />
              <Row label="Estado" value={o.subscription.status} />
              {o.subscription.trial_ends_at && <Row label="La prueba termina" value={formatDateTime(o.subscription.trial_ends_at)} />}
              {o.subscription.current_period_end && <Row label={o.subscription.source === "manual" ? "Vence" : "Período hasta"} value={formatDateTime(o.subscription.current_period_end)} />}
              {o.subscription.cancel_at_period_end && <Row label="Cancelación" value="Programada al fin del período" />}
              {o.subscription.stripe_customer_id && <Row label="Cliente Stripe" value={o.subscription.stripe_customer_id} mono />}
              {o.subscription.stripe_subscription_id && <Row label="Suscripción Stripe" value={o.subscription.stripe_subscription_id} mono />}
            </dl>
          ) : (
            <p className="mt-3 text-sm text-muted">Nunca tuvo suscripción.</p>
          )}
        </Card>

        <Card>
          <h2 className="font-semibold">Consumo del período</h2>
          <dl className="mt-3 space-y-1.5 text-sm">
            <Row label="Mensajes de IA" value={`${formatNumber(o.messages_used)} de ${formatNumber(o.messages_limit)}`} />
            <Row label="Costo de IA este mes" value={formatMicros(o.ai_cost_micros_month)} />
            <Row label="Período" value={`${formatDateTime(o.period_start)} → ${formatDateTime(o.period_end)}`} />
          </dl>
        </Card>

        <Card>
          <h2 className="font-semibold">Equipo ({o.members.length})</h2>
          <ul className="mt-3 divide-y divide-border text-sm">
            {o.members.map((m) => (
              <li key={m.id} className="flex items-center justify-between gap-3 py-2">
                <span>
                  {m.name} <span className="text-muted">· {m.email}</span>
                </span>
                <Badge>{m.role}</Badge>
              </li>
            ))}
          </ul>
        </Card>

        <Card>
          <h2 className="font-semibold">Agentes ({o.agents.length})</h2>
          {o.agents.length === 0 && <p className="mt-3 text-sm text-muted">Sin agentes.</p>}
          <ul className="mt-3 divide-y divide-border text-sm">
            {o.agents.map((a) => (
              <li key={a.id} className="py-2">
                <div className="flex items-center justify-between gap-3">
                  <span className="font-medium">{a.name}</span>
                  <Badge tone={a.is_active ? "success" : "neutral"}>{a.is_active ? "Activo" : "Inactivo"}</Badge>
                </div>
                <p className="text-xs text-muted">{a.domains.length ? a.domains.join(", ") : "Sin sitios autorizados"}</p>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      <Actions org={o} plans={plans.data ?? []} onDone={() => void org.reload()} />

      <Card>
        <h2 className="font-semibold">Últimas acciones</h2>
        <div className="mt-3">
          <AuditList rows={o.audit} withOrganization={false} />
        </div>
      </Card>
    </div>
  );
}

function Row({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-muted">{label}</dt>
      <dd className={mono ? "break-all font-mono text-xs" : "text-right font-medium"}>{value}</dd>
    </div>
  );
}

/** Acciones de soporte: todas quedan en el registro de auditoría con quién las hizo. */
function Actions({ org, plans, onDone }: { org: AdminOrganizationDetail; plans: AdminPlan[]; onDone: () => void }) {
  const [plan, setPlan] = useState("");
  const [until, setUntil] = useState("");
  const [note, setNote] = useState("");
  const [days, setDays] = useState("7");
  const [reason, setReason] = useState("");

  const grant = useAction(async () => {
    await api(`/admin/organizations/${org.id}/plan`, { method: "POST", body: { plan: plan || plans[0]?.slug, until: until || null, note: note || null } });
    onDone();
  });
  const extend = useAction(async () => {
    await api(`/admin/organizations/${org.id}/extend-trial`, { method: "POST", body: { days: Number(days) } });
    onDone();
  });
  const suspend = useAction(async () => {
    await api(`/admin/organizations/${org.id}/suspend`, { method: "POST", body: { reason } });
    setReason("");
    onDone();
  });
  const unsuspend = useAction(async () => {
    await api(`/admin/organizations/${org.id}/unsuspend`, { method: "POST" });
    onDone();
  });

  return (
    <Card>
      <h2 className="font-semibold">Acciones</h2>
      <div className="mt-4 grid gap-8 lg:grid-cols-3">
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            void grant.run();
          }}
        >
          <h3 className="text-sm font-medium">Dar un plan por cortesía</h3>
          {grant.done && <Alert tone="success">Plan asignado.</Alert>}
          {grant.error && <Alert>{grant.error.field("plan") ?? grant.error.field("until") ?? grant.error.message}</Alert>}
          <Select label="Plan" value={plan || plans[0]?.slug || ""} onChange={(e) => setPlan(e.target.value)}>
            {plans.map((p) => (
              <option key={p.slug} value={p.slug}>
                {p.name}
              </option>
            ))}
          </Select>
          <Input label="Vence (opcional)" type="date" value={until} onChange={(e) => setUntil(e.target.value)} hint="Vacío = sin vencimiento." />
          <Input label="Nota" value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} placeholder="Piloto con el cliente…" />
          {org.subscription?.source === "stripe" && (
            <p className="text-xs text-muted">Esta empresa paga por Stripe: la cortesía reemplaza su plan hasta que Stripe envíe un evento nuevo.</p>
          )}
          <Button type="submit" variant="secondary" loading={grant.pending}>
            Asignar plan
          </Button>
        </form>

        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            void extend.run();
          }}
        >
          <h3 className="text-sm font-medium">Extender la prueba</h3>
          {extend.done && <Alert tone="success">Prueba extendida.</Alert>}
          {extend.error && <Alert>{extend.error.field("days") ?? extend.error.message}</Alert>}
          <Input label="Días" type="number" min={1} max={90} value={days} onChange={(e) => setDays(e.target.value)} />
          <Button type="submit" variant="secondary" loading={extend.pending} disabled={org.subscription?.status !== "trialing"}>
            Extender
          </Button>
          {org.subscription?.status !== "trialing" && <p className="text-xs text-muted">Solo aplica a empresas en período de prueba.</p>}
        </form>

        <div className="space-y-3">
          <h3 className="text-sm font-medium">{org.suspended ? "Reactivar" : "Suspender"}</h3>
          {(suspend.error ?? unsuspend.error) && <Alert>{(suspend.error ?? unsuspend.error)!.field("reason") ?? (suspend.error ?? unsuspend.error)!.message}</Alert>}
          {org.suspended ? (
            <Button variant="secondary" loading={unsuspend.pending} onClick={() => void unsuspend.run()}>
              Reactivar la empresa
            </Button>
          ) : (
            <form
              className="space-y-3"
              onSubmit={(e) => {
                e.preventDefault();
                if (window.confirm(`¿Suspender a ${org.name}? Sus agentes dejarán de responder de inmediato.`)) void suspend.run();
              }}
            >
              <Input label="Motivo" required value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} placeholder="Uso abusivo, fraude…" />
              <Button type="submit" variant="danger" loading={suspend.pending}>
                Suspender
              </Button>
            </form>
          )}
        </div>
      </div>
    </Card>
  );
}
