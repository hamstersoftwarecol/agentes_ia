import type { Metadata } from "next";
import { PlansView } from "./plans-view";

export const metadata: Metadata = { title: "Plataforma · Planes" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminPlansPage() {
  return <PlansView />;
}
