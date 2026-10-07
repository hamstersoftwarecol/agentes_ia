import type { Metadata } from "next";
import { AnalyticsView } from "./analytics-view";

export const metadata: Metadata = { title: "Analíticas" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AnalyticsPage() {
  return <AnalyticsView />;
}
