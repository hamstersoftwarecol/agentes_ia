"use client";

import Link from "next/link";
import { formatMoney, formatNumber } from "@/lib/format";
import type { Plan } from "@/lib/types";
import { useApi } from "@/lib/use-api";

/** Precios de la página de inicio: salen de la misma tabla de planes que usa la facturación (un solo lugar que mantener). */
export function PricingSection() {
  const plans = useApi<Plan[]>("/plans");
  if (!plans.data?.length) return null;

  return (
    <section className="pb-24" aria-labelledby="precios">
      <h2 id="precios" className="text-center text-2xl font-semibold tracking-tight">
        Planes simples, sin sorpresas
      </h2>
      <p className="mt-2 text-center text-sm text-muted">Empieza con una prueba gratuita. Sin tarjeta.</p>
      <div className="mt-8 grid gap-4 md:grid-cols-3">
        {plans.data.map((plan) => (
          <div key={plan.slug} className="flex flex-col rounded-xl border border-border bg-card p-6">
            <h3 className="font-semibold">{plan.name}</h3>
            <p className="mt-2">
              <span className="text-3xl font-semibold">{formatMoney(plan.price_cents)}</span>
              <span className="text-sm text-muted"> / mes</span>
            </p>
            <ul className="mt-4 space-y-1.5 text-sm">
              <li>
                {plan.max_agents} agente{plan.max_agents === 1 ? "" : "s"} de IA
              </li>
              <li>{formatNumber(plan.max_monthly_messages)} mensajes al mes</li>
              <li>{plan.max_sites === null ? "Sitios web ilimitados" : `${plan.max_sites} sitio${plan.max_sites === 1 ? "" : "s"} web`}</li>
              <li>
                {plan.max_users} usuario{plan.max_users === 1 ? "" : "s"} en el equipo
              </li>
              <li>Base de conocimiento y captura de leads</li>
              <li className={plan.features.human_inbox ? "" : "text-muted line-through"}>
                {plan.features.human_inbox ? "✓ " : "— "}Atención humana en el chat
              </li>
              <li className={plan.features.widget_customization ? "" : "text-muted line-through"}>
                {plan.features.widget_customization ? "✓ " : "— "}Widget personalizable
              </li>
              <li className={plan.features.analytics ? "" : "text-muted line-through"}>
                {plan.features.analytics ? "✓ " : "— "}Analíticas
              </li>
            </ul>
            <Link
              href="/register"
              className="mt-6 rounded-lg border border-border px-4 py-2 text-center text-sm font-medium hover:bg-brand-soft"
            >
              Empezar gratis
            </Link>
          </div>
        ))}
      </div>
    </section>
  );
}
