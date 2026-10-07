import type { Metadata } from "next";
import { KnowledgeListView } from "./knowledge-list-view";

export const metadata: Metadata = { title: "Conocimiento" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function KnowledgePage() {
  return <KnowledgeListView />;
}
