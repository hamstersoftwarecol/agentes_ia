import type { Metadata } from "next";
import { DashboardView } from "./dashboard-view";

export const metadata: Metadata = { title: "Resumen" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function DashboardPage() {
  return <DashboardView />;
}
