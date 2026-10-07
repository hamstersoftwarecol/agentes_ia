"use client";

import Link from "next/link";
import { DailyBars } from "@/components/charts/DailyBars";
import { useBilling } from "@/components/BillingProvider";
import { Alert, Badge, Card, cn } from "@/components/ui";
import { useAuth } from "@/lib/auth";
import { formatCompact, formatNumber, formatRecent } from "@/lib/format";
import { formatPhone, leadStatusLabel } from "@/lib/status";
import type { Agent, DashboardSummary, KnowledgeBase } from "@/lib/types";
import { useApi } from "@/lib/use-api";

export function DashboardView() {
  const { user } = useAuth();
  const organization = user!.organization!;
  const agents = useApi<Agent[]>("/agents");
  const bases = useApi<KnowledgeBase[]>("/knowledge-bases");
  const summary = useApi<DashboardSummary>("/dashboard/summary", () => 30000);
  const { billing } = useBilling();
  const data = summary.data;

  const stats: { label: string; value: number }[] = [
    { label: "Chats (30 días)", value: data?.conversations_30d ?? 0 },
    { label: "Leads (30 días)", value: data?.leads_30d ?? 0 },
    { label: "Mensajes (30 días)", value: data?.messages_30d ?? 0 },
  ];

  const steps: { label: string; done: boolean; href?: string }[] = [
    { label: "Crear tu cuenta", done: true },
    { label: "Verificar tu correo", done: true },
    { label: `Crear la empresa «${organization.name}»`, done: true },
    {
      label: "Cargar el conocimiento de tu negocio",
      done: bases.data?.some((kb) => (kb.ready_documents_count ?? 0) > 0) ?? false,
      href: "/knowledge",
    },
    { label: "Crear tu primer agente", done: (agents.data?.length ?? 0) > 0, href: "/agents/new" },
    {
      label: "Instalar el chat en tu sitio web",
      done: (data?.conversations_total ?? 0) > 0,
      href: agents.data?.[0] ? `/agents/${agents.data[0].id}` : "/agents/new",
    },
  ];
  const onboarding = steps.some((s) => !s.done);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Hola, {user!.name.split(" ")[0]} 👋</h1>
        <p className="mt-1 text-sm text-muted">Este es el resumen de {organization.name}.</p>
      </div>

      {(data?.waiting_conversations ?? 0) > 0 && (
        <Alert tone="info">
          <Link href="/conversations" className="font-medium underline">
            {data!.waiting_conversations} conversación{data!.waiting_conversations === 1 ? "" : "es"} esperando a una persona
          </Link>
          . La IA no responde ahí hasta que alguien de tu equipo la tome.
        </Alert>
      )}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {stats.map(({ label, value }) => (
          <Card key={label} className="p-4">
            <p className="text-sm text-muted">{label}</p>
            {/* Cifras proporcionales (no tabulares): son números sueltos, no una columna. */}
            <p className="mt-1 text-2xl font-semibold" title={formatNumber(value)}>
              {formatCompact(value)}
            </p>
          </Card>
        ))}
        <Card className="p-4">
          <p className="text-sm text-muted">Consumo del plan</p>
          <p className="mt-1 text-2xl font-semibold" title={billing ? `${formatNumber(billing.usage.messages_used)} de ${formatNumber(billing.usage.messages_limit)} mensajes` : undefined}>
            {billing ? `${billing.usage.percent}%` : "—"}
          </p>
          {/* Medidor: la pista es un tono más claro del mismo color y el relleno pasa a rojo al acercarse al tope. */}
          <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-brand-soft" aria-hidden>
            <div className={cn("h-full rounded-full", (billing?.usage.percent ?? 0) >= 90 ? "bg-danger" : "bg-brand")} style={{ width: `${billing?.usage.percent ?? 0}%` }} />
          </div>
        </Card>
      </div>

      <Card>
        <h2 className="font-semibold">Conversaciones por día</h2>
        <p className="mt-0.5 text-sm text-muted">Últimos 30 días</p>
        <div className="mt-4">
          <DailyBars
            data={(data?.daily ?? []).map((d) => ({ date: d.date, value: d.conversations }))}
            unit={["conversación", "conversaciones"]}
            label="Conversaciones por día, últimos 30 días"
          />
        </div>
      </Card>

      <div className={cn("grid gap-6", onboarding && "lg:grid-cols-2")}>
        <Card>
          <div className="flex items-baseline justify-between gap-3">
            <h2 className="font-semibold">Últimos leads</h2>
            <Link href="/leads" className="text-sm text-brand hover:underline">
              Ver todos
            </Link>
          </div>
          {data && data.recent_leads.length === 0 && (
            <p className="mt-3 text-sm text-muted">
              Aún no hay leads. Cuando un cliente deje su teléfono o correo en el chat, aparecerá aquí.
            </p>
          )}
          <ul className="mt-3 divide-y divide-border">
            {data?.recent_leads.map((lead) => (
              <li key={lead.id} className="flex items-start justify-between gap-3 py-2.5 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium">{lead.name ?? "Sin nombre"}</p>
                  <p className="truncate text-muted">{lead.interest ?? (lead.phone ? formatPhone(lead.phone) : lead.email)}</p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                  <Badge tone={lead.status === "new" ? "info" : lead.status === "converted" ? "success" : "neutral"}>
                    {leadStatusLabel(lead.status)}
                  </Badge>
                  <span className="text-xs text-muted">{formatRecent(lead.created_at)}</span>
                </div>
              </li>
            ))}
          </ul>
        </Card>

        {onboarding && (
          <Card>
            <h2 className="font-semibold">Primeros pasos</h2>
            <ul className="mt-4 space-y-3">
              {steps.map((step) => (
                <li key={step.label} className="flex items-center gap-3 text-sm">
                  <span
                    aria-hidden
                    className={cn(
                      "grid size-5 place-items-center rounded-full border text-xs",
                      step.done ? "border-success bg-success-soft text-success" : "border-border text-muted",
                    )}
                  >
                    {step.done ? "✓" : ""}
                  </span>
                  {!step.done && step.href ? (
                    <Link href={step.href} className="text-brand hover:underline">
                      {step.label}
                    </Link>
                  ) : (
                    <span className={cn(!step.done && "text-muted")}>{step.label}</span>
                  )}
                </li>
              ))}
            </ul>
            <p className="mt-5 text-sm text-muted">
              Completa también los datos y el{" "}
              <Link href="/settings/organization" className="text-brand hover:underline">
                horario de atención de tu empresa
              </Link>
              : tu agente lo usa para informar cuándo responde una persona.
            </p>
          </Card>
        )}
      </div>
    </div>
  );
}
