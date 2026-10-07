"use client";

import { DailyBars } from "@/components/charts/DailyBars";
import { Alert, Card, cn, Spinner } from "@/components/ui";
import { formatCompact, formatMoney, formatNumber } from "@/lib/format";
import type { AdminOverview } from "@/lib/types";
import { useApi } from "@/lib/use-api";

export function AdminOverviewView() {
  const overview = useApi<AdminOverview>("/admin/overview", () => 60000);
  const o = overview.data;

  if (overview.error) return <Alert>{overview.error.message}</Alert>;
  if (!o) return <Spinner className="size-5" />;

  const monthName = new Intl.DateTimeFormat("es", { month: "long", year: "numeric" }).format(new Date(`${o.month}T12:00:00`));
  const month = monthName.charAt(0).toUpperCase() + monthName.slice(1);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Resumen de la plataforma</h1>
        <p className="mt-1 text-sm text-muted">{month}</p>
      </div>

      <Card>
        <h2 className="font-semibold">Margen del mes</h2>
        <p className="mt-0.5 text-sm text-muted">Ingresos − costo de IA − infraestructura</p>
        <div className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <Figure label="Ingresos (MRR estimado)" value={formatMoney(o.mrr_cents)} />
          <Figure label="Costo de IA" value={formatMoney(o.ai_cost_cents)} />
          <Figure label="Infraestructura" value={formatMoney(o.infrastructure_cost_cents)} />
          <Figure label="Margen" value={formatMoney(o.margin_cents)} emphasis={o.margin_cents < 0 ? "negative" : "positive"} />
        </div>
        <p className="mt-4 text-xs text-muted">
          Los ingresos son el precio de lista de los planes que pagan por Stripe (sin cortesías, pruebas ni descuentos). El costo de IA es una estimación
          con los precios de <code>config/ai.php</code>; la factura real es la del proveedor.
        </p>
      </Card>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Stat label="Empresas" value={formatNumber(o.organizations_total)} sub={`${o.organizations_new_30d} nuevas en 30 días`} />
        <Stat label="Pagando" value={formatNumber(o.subscriptions.paying)} sub={o.subscriptions.past_due ? `${o.subscriptions.past_due} con pago pendiente` : "al día"} />
        <Stat label="En prueba" value={formatNumber(o.subscriptions.trialing)} sub={`${o.subscriptions.manual} por cortesía · ${o.subscriptions.canceled} canceladas`} />
        <Stat label="Mensajes de IA" value={formatCompact(o.messages_month)} sub={`${formatCompact(o.tokens_month)} tokens`} />
      </div>

      <Card>
        <h2 className="font-semibold">Costo de IA por día</h2>
        <p className="mt-0.5 text-sm text-muted">Últimos 30 días</p>
        <div className="mt-4">
          <DailyBars
            data={o.daily.map((d) => ({ date: d.date, value: d.ai_cost_cents }))}
            unit={["de costo", "de costo"]}
            label="Costo de IA por día, últimos 30 días"
            format={(cents) => formatMoney(Math.round(cents))}
          />
        </div>
      </Card>
    </div>
  );
}

function Figure({ label, value, emphasis }: { label: string; value: string; emphasis?: "positive" | "negative" }) {
  return (
    <div>
      <p className="text-sm text-muted">{label}</p>
      <p className={cn("mt-1 text-2xl font-semibold", emphasis === "negative" && "text-danger", emphasis === "positive" && "text-success")}>{value}</p>
    </div>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-muted">{label}</p>
      <p className="mt-1 text-2xl font-semibold">{value}</p>
      <p className="mt-0.5 text-xs text-muted">{sub}</p>
    </Card>
  );
}
