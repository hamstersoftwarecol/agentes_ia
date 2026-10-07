import type { Metadata } from "next";
import { LeadsView } from "./leads-view";

export const metadata: Metadata = { title: "Leads" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function LeadsPage() {
  return <LeadsView />;
}
