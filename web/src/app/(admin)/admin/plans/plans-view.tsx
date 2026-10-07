"use client";

import { useState } from "react";
import { Alert, Badge, Button, Card, Input, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import type { AdminPlan } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

export function PlansView() {
  const plans = useApi<AdminPlan[]>("/admin/plans");

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Planes</h1>
        <p className="mt-1 text-sm text-muted">
          Los cambios de topes y funciones se aplican de inmediato a todas las empresas con ese plan. El precio de lista es el que se muestra y se usa
          para estimar los ingresos; el cobro real lo define el precio de Stripe asociado.
        </p>
      </div>
      {plans.loading && <Spinner className="size-5" />}
      {plans.error && <Alert>{plans.error.message}</Alert>}
      <div className="grid gap-6 xl:grid-cols-3">
        {plans.data?.map((plan) => (
          <PlanEditor key={plan.id} plan={plan} onSaved={() => void plans.reload()} />
        ))}
      </div>
    </div>
  );
}

function PlanEditor({ plan, onSaved }: { plan: AdminPlan; onSaved: () => void }) {
  const [form, setForm] = useState({
    name: plan.name,
    price: String(plan.price_cents / 100),
    max_agents: String(plan.max_agents),
    max_monthly_messages: String(plan.max_monthly_messages),
    max_sites: plan.max_sites === null ? "" : String(plan.max_sites),
    max_users: String(plan.max_users),
    stripe_price_id: plan.stripe_price_id ?? "",
    human_inbox: plan.features.human_inbox,
    widget_customization: plan.features.widget_customization,
    analytics: plan.features.analytics,
    is_active: plan.is_active,
  });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [key]: e.target.value }));
  const toggle = (key: "human_inbox" | "widget_customization" | "analytics" | "is_active") => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [key]: e.target.checked }));

  const save = useAction(async () => {
    await api(`/admin/plans/${plan.id}`, {
      method: "PATCH",
      body: {
        name: form.name,
        price_cents: Math.round(Number(form.price) * 100),
        max_agents: Number(form.max_agents),
        max_monthly_messages: Number(form.max_monthly_messages),
        max_sites: form.max_sites === "" ? null : Number(form.max_sites),
        max_users: Number(form.max_users),
        stripe_price_id: form.stripe_price_id.trim() === "" ? null : form.stripe_price_id.trim(),
        features: { human_inbox: form.human_inbox, widget_customization: form.widget_customization, analytics: form.analytics },
        is_active: form.is_active,
      },
    });
    onSaved();
  });
  const err = (field: string) => save.error?.field(field);

  return (
    <Card>
      <div className="flex items-center justify-between gap-3">
        <h2 className="font-semibold">
          {plan.slug} {!plan.is_active && <Badge>Inactivo</Badge>}
        </h2>
        <span className="text-xs text-muted">{plan.subscribers} empresa{plan.subscribers === 1 ? "" : "s"}</span>
      </div>
      <form
        className="mt-4 space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          void save.run();
        }}
      >
        {save.done && <Alert tone="success">Plan guardado.</Alert>}
        {save.error && !Object.keys(save.error.errors).length && <Alert>{save.error.message}</Alert>}
        <Input label="Nombre" value={form.name} onChange={set("name")} error={err("name")} />
        <Input label="Precio mensual (USD)" type="number" min={0} step="0.01" value={form.price} onChange={set("price")} error={err("price_cents")} />
        <div className="grid grid-cols-2 gap-3">
          <Input label="Agentes" type="number" min={1} value={form.max_agents} onChange={set("max_agents")} error={err("max_agents")} />
          <Input label="Sitios" type="number" min={1} value={form.max_sites} onChange={set("max_sites")} error={err("max_sites")} hint="Vacío = ilimitados" />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Input label="Mensajes de IA al mes" type="number" min={1} value={form.max_monthly_messages} onChange={set("max_monthly_messages")} error={err("max_monthly_messages")} />
          <Input label="Usuarios" type="number" min={1} value={form.max_users} onChange={set("max_users")} error={err("max_users")} hint="Incluye invitaciones pendientes" />
        </div>
        <Input label="ID de precio en Stripe" value={form.stripe_price_id} onChange={set("stripe_price_id")} error={err("stripe_price_id")} placeholder="price_…" hint="Sin él, el plan no se puede contratar desde la app." />
        <div className="space-y-1.5 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.human_inbox} onChange={toggle("human_inbox")} className="size-4 accent-[var(--brand)]" /> Atención humana en el chat
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.widget_customization} onChange={toggle("widget_customization")} className="size-4 accent-[var(--brand)]" /> Widget personalizable
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.analytics} onChange={toggle("analytics")} className="size-4 accent-[var(--brand)]" /> Analíticas
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.is_active} onChange={toggle("is_active")} className="size-4 accent-[var(--brand)]" /> Se ofrece a clientes nuevos
          </label>
        </div>
        <Button type="submit" loading={save.pending}>
          Guardar plan
        </Button>
      </form>
    </Card>
  );
}
