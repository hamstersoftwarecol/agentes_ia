import type { Metadata } from "next";
import { NewAgentView } from "./new-agent-view";

export const metadata: Metadata = { title: "Nuevo agente" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function NewAgentPage() {
  return <NewAgentView />;
}
