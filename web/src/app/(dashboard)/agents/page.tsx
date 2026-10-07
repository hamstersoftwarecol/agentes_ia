import type { Metadata } from "next";
import { AgentsView } from "./agents-view";

export const metadata: Metadata = { title: "Agentes" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AgentsPage() {
  return <AgentsView />;
}
