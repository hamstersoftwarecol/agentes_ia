import type { Metadata } from "next";
import { AdminOverviewView } from "./overview-view";

export const metadata: Metadata = { title: "Plataforma · Resumen" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminOverviewPage() {
  return <AdminOverviewView />;
}
