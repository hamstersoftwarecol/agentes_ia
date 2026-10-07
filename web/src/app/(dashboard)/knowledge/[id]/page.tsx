import type { Metadata } from "next";
import { KnowledgeDetailView } from "./knowledge-detail-view";

export const metadata: Metadata = { title: "Base de conocimiento" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function KnowledgeDetailPage() {
  return <KnowledgeDetailView />;
}
