import type { Metadata } from "next";
import { OrganizationsView } from "./organizations-view";

export const metadata: Metadata = { title: "Plataforma · Empresas" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminOrganizationsPage() {
  return <OrganizationsView />;
}
