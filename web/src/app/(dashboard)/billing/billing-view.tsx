"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef } from "react";
import { PlanLimitAlert, useBilling } from "@/components/BillingProvider";
import { Alert, Badge, Button, Card, cn, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { formatDateTime, formatMoney, formatNumber, formatRecent } from "@/lib/format";
import type { BillingState, Plan } from "@/lib/types";
import { useAction } from "@/lib/use-action";

const STATE: Record<BillingState, { label: string; tone: "success" | "info" | "danger" | "neutral" }> = {
  active: { label: "Activo", tone: "success" },
  trialing: { label: "En prueba", tone: "info" },
  past_due: { label: "Pago pendiente", tone: "danger" },
  expired: { label: "Prueba terminada", tone: "danger" },
  canceled: { label: "Cancelado", tone: "danger" },
  unpaid: { label: "Impago", tone: "danger" },
  incomplete: { label: "Pago incompleto", tone: "danger" },
  suspended: { label: "Suspendido", tone: "danger" },
  none: { label: "Sin plan", tone: "neutral" },
};

export function BillingView() {
  const { billing, reload } = useBilling();
  const checkout = useSearchParams().get("checkout");

  // Al volver de Stripe el webhook puede tardar unos segundos en activar el plan: se consulta hasta verlo.
  const tries = useRef(0);
  useEffect(() => {
    if (checkout !== "success" || (billing?.stripe.paying && billing.state === "active")) return;
    if (tries.current >= 10) return;
    const timer = setTimeout(() => {
      tries.current += 1;
      void reload();
    }, 3000);
    return () => clearTimeout(timer);
  }, [checkout, billing, reload]);

  const portal = useAction(async () => {
    const { url } = await api<{ url: string }>("/billing/portal", { method: "POST" });
    window.location.href = url;
  });

  if (!billing) return <Spinner className="size-5" />;
  const state = STATE[billing.state];
  const usage = billing.usage;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Facturación</h1>
        <p className="mt-1 text-sm text-muted">Tu plan, el consumo del período y cómo cambiarlo.</p>
      </div>

      {checkout === "success" && (
        <Alert tone="success">
          {billing.stripe.paying ? "¡Listo! Tu plan está activo." : "¡Gracias! Estamos activando tu plan; en unos segundos aparecerá aquí."}
        </Alert>
      )}
      {checkout === "canceled" && <Alert tone="info">Cancelaste el pago. No se hizo ningún cobro.</Alert>}
      {portal.error && <Alert>{portal.error.message}</Alert>}

      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-sm text-muted">Plan actual</p>
            <p className="mt-0.5 text-2xl font-semibold">
              {billing.plan?.name ?? "Sin plan"}{" "}
              <Badge tone={state.tone}>{state.label}</Badge>
            </p>
            {billing.plan && (
              <p className="mt-1 text-sm text-muted">
                {billing.source === "stripe" ? `${formatMoney(billing.plan.price_cents)} al mes` : billing.source === "manual" ? "Plan por cortesía" : "Período de prueba gratuito"}
              </p>
            )}
          </div>
          {billing.can_manage && billing.stripe.has_customer && (
            <Button variant="secondary" loading={portal.pending} onClick={() => void portal.run()}>
              Método de pago y facturas
            </Button>
          )}
        </div>

        <dl className="mt-5 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          {billing.trial_ends_at && (
            <div>
              <dt className="text-muted">La prueba termina</dt>
              <dd className="font-medium">{formatDateTime(billing.trial_ends_at)}</dd>
            </div>
          )}
          <div>
            <dt className="text-muted">{billing.cancel_at_period_end ? "Se cancela el" : "El período se renueva el"}</dt>
            <dd className="font-medium">{formatRecent(billing.period_end)}</dd>
          </div>
          <div>
            <dt className="text-muted">Agentes activos</dt>
            <dd className="font-medium">
              {billing.agents.active} de {billing.agents.limit}
            </dd>
          </div>
          <div>
            <dt className="text-muted">Usuarios del equipo</dt>
            <dd className="font-medium">
              <Link href="/team" className="hover:underline">
                {billing.users.members} de {billing.users.limit}
              </Link>
            </dd>
          </div>
        </dl>
      </Card>

      <Card>
        <div className="flex items-baseline justify-between gap-3">
          <h2 className="font-semibold">Mensajes de IA de este período</h2>
          <span className="text-sm text-muted">
            {formatNumber(usage.messages_used)} de {formatNumber(usage.messages_limit)}
          </span>
        </div>
        {/* La pista es un tono más claro del mismo color: el estado se lee en toda la barra, no solo en el relleno. */}
        <div
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={usage.percent}
          aria-label="Consumo de mensajes del plan"
          className="mt-3 h-2.5 overflow-hidden rounded-full bg-brand-soft"
        >
          <div className={cn("h-full rounded-full", usage.percent >= 90 ? "bg-danger" : "bg-brand")} style={{ width: `${Math.max(usage.percent, usage.messages_used > 0 ? 2 : 0)}%` }} />
        </div>
        <p className="mt-2 text-sm text-muted">
          {usage.percent}% usado. Cuentan las respuestas de tus agentes a clientes; las pruebas desde el panel no.
        </p>
      </Card>

      <section className="space-y-4">
        <h2 className="text-lg font-semibold">Planes</h2>
        {!billing.can_manage && <p className="text-sm text-muted">Solo el propietario de la empresa puede cambiar el plan.</p>}
        {billing.can_manage && !billing.stripe.configured && (
          <Alert tone="info">Los pagos todavía no están configurados en esta instalación; aún no se puede contratar desde aquí.</Alert>
        )}
        <div className="grid gap-4 lg:grid-cols-3">
          {billing.plans.map((plan) => (
            <PlanCard key={plan.slug} plan={plan} current={billing.plan?.slug === plan.slug && billing.state !== "expired"} canBuy={billing.can_manage && billing.stripe.configured} />
          ))}
        </div>
      </section>
    </div>
  );
}

function PlanCard({ plan, current, canBuy }: { plan: Plan; current: boolean; canBuy: boolean }) {
  const choose = useAction(async () => {
    const { url } = await api<{ url: string }>("/billing/checkout", { method: "POST", body: { plan: plan.slug } });
    window.location.href = url;
  });

  const rows: [string, boolean][] = [
    ["Atención humana en el chat", plan.features.human_inbox],
    ["Personalizar el widget", plan.features.widget_customization],
    ["Analíticas", plan.features.analytics],
  ];

  return (
    <Card className={cn("flex flex-col", current && "border-brand")}>
      <div className="flex items-baseline justify-between gap-2">
        <h3 className="text-lg font-semibold">{plan.name}</h3>
        {current && <Badge tone="info">Tu plan</Badge>}
      </div>
      <p className="mt-2">
        <span className="text-3xl font-semibold">{formatMoney(plan.price_cents)}</span>
        <span className="text-sm text-muted"> / mes</span>
      </p>
      {plan.description && <p className="mt-1 text-sm text-muted">{plan.description}</p>}
      <ul className="mt-4 space-y-1.5 text-sm">
        <li>
          {plan.max_agents} agente{plan.max_agents === 1 ? "" : "s"}
        </li>
        <li>{formatNumber(plan.max_monthly_messages)} mensajes de IA al mes</li>
        <li>{plan.max_sites === null ? "Sitios web ilimitados" : `${plan.max_sites} sitio${plan.max_sites === 1 ? "" : "s"} web`}</li>
        <li>
          {plan.max_users} usuario{plan.max_users === 1 ? "" : "s"} en el equipo
        </li>
        <li>Base de conocimiento y captura de leads</li>
        {rows.map(([label, included]) => (
          <li key={label} className={cn(!included && "text-muted line-through")}>
            {included ? "✓ " : "— "}
            {label}
          </li>
        ))}
      </ul>
      <div className="mt-auto pt-5">
        {choose.error && (
          <div className="mb-3">
            <PlanLimitAlert error={choose.error} />
          </div>
        )}
        {current ? (
          <p className="text-center text-sm text-muted">Este es tu plan actual</p>
        ) : (
          <Button className="w-full" variant={plan.slug === "business" ? "primary" : "secondary"} disabled={!canBuy || !plan.purchasable} loading={choose.pending} onClick={() => void choose.run()}>
            {plan.purchasable ? `Elegir ${plan.name}` : "Próximamente"}
          </Button>
        )}
      </div>
    </Card>
  );
}
