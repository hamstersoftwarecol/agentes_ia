import type { Metadata } from "next";
import { Suspense } from "react";
import { BillingView } from "./billing-view";

export const metadata: Metadata = { title: "Facturación" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function BillingPage() {
  // useSearchParams (?checkout=success al volver de Stripe) exige un límite de Suspense.
  return (
    <Suspense>
      <BillingView />
    </Suspense>
  );
}
