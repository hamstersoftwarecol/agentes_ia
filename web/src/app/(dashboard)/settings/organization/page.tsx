import type { Metadata } from "next";
import { OrganizationView } from "./organization-view";

export const metadata: Metadata = { title: "Empresa" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function OrganizationPage() {
  return <OrganizationView />;
}
