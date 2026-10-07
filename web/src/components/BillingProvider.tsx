"use client";

import Link from "next/link";
import { createContext, useContext } from "react";
import { Alert } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { daysUntil, formatRecent } from "@/lib/format";
import type { BillingSummary } from "@/lib/types";
import { useApi } from "@/lib/use-api";

type Value = { billing: BillingSummary | undefined; reload: () => Promise<void> };
const BillingContext = createContext<Value | null>(null);

/** Plan y consumo de la empresa, compartidos por todo el panel (se refrescan cada minuto). */
export function BillingProvider({ children }: { children: React.ReactNode }) {
  const summary = useApi<BillingSummary>("/billing", () => 60000);
  return <BillingContext.Provider value={{ billing: summary.data, reload: summary.reload }}>{children}</BillingContext.Provider>;
}

export function useBilling() {
  const value = useContext(BillingContext);
  if (!value) throw new Error("useBilling debe usarse dentro de <BillingProvider>");
  return value;
}

/** Un solo aviso, el más urgente: lo que impide que los agentes atiendan va antes que un recordatorio. */
export function PlanBanner() {
  const { billing } = useBilling();
  if (!billing) return null;

  const manage = (
    <Link href="/billing" className="font-medium underline">
      {billing.can_manage ? "Ver planes" : "Ver estado del plan"}
    </Link>
  );

  switch (billing.state) {
    case "suspended":
      return (
        <Alert>
          Tu cuenta está suspendida{billing.suspension_reason ? `: ${billing.suspension_reason}` : ""}. Tus agentes no responden. Contacta a soporte.
        </Alert>
      );
    case "expired":
    case "none":
      return <Alert>Tu período de prueba terminó y tus agentes dejaron de responder. {manage} para reactivarlos.</Alert>;
    case "canceled":
    case "unpaid":
    case "incomplete":
      return <Alert>Tu suscripción no está activa y tus agentes dejaron de responder. {manage}.</Alert>;
    case "past_due":
      return <Alert tone="info">No pudimos cobrar tu última factura. Actualiza tu método de pago para no perder el servicio. {manage}.</Alert>;
  }

  if (billing.usage.percent >= 100) {
    return <Alert>Se agotaron los mensajes de tu plan: tus agentes no responderán nuevos mensajes hasta el {formatRecent(billing.period_end)}. {manage}.</Alert>;
  }
  if (billing.usage.percent >= 80) {
    return (
      <Alert tone="info">
        Ya usaste el {billing.usage.percent}% de los mensajes de tu plan este período. {manage}.
      </Alert>
    );
  }
  if (billing.state === "trialing" && billing.trial_ends_at) {
    const days = daysUntil(billing.trial_ends_at);
    return (
      <Alert tone="info">
        Estás en tu período de prueba: te {days === 1 ? "queda 1 día" : `quedan ${days} días`}. {manage} antes de que termine.
      </Alert>
    );
  }
  if (billing.cancel_at_period_end) {
    return <Alert tone="info">Tu plan se cancelará el {formatRecent(billing.period_end)}. {manage}.</Alert>;
  }
  return null;
}

/** Error de la API con enlace a los planes cuando el motivo es el límite del plan. */
export function PlanLimitAlert({ error }: { error: ApiError }) {
  return (
    <Alert tone={error.code === "plan_limit" ? "info" : "danger"}>
      {error.message}
      {error.code === "plan_limit" && (
        <>
          {" "}
          <Link href="/billing" className="font-medium underline">
            Ver planes
          </Link>
        </>
      )}
    </Alert>
  );
}
