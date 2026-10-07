import type { Metadata } from "next";
import { AuditView } from "@/components/admin/views";

export const metadata: Metadata = { title: "Plataforma · Auditoría" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminAuditViewPage() {
  return <AuditView />;
}
