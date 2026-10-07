import type { Metadata } from "next";
import { AgentView } from "./agent-view";

export const metadata: Metadata = { title: "Agente" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AgentPage() {
  return <AgentView />;
}
