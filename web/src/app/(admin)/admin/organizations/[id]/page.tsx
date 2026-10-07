import type { Metadata } from "next";
import { OrganizationDetailView } from "./organization-detail-view";

export const metadata: Metadata = { title: "Plataforma · Empresa" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminOrganizationPage() {
  return <OrganizationDetailView />;
}
