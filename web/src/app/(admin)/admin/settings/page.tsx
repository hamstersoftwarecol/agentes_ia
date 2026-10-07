import type { Metadata } from "next";
import { SettingsView } from "@/components/admin/views";

export const metadata: Metadata = { title: "Plataforma · Configuración" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminSettingsViewPage() {
  return <SettingsView />;
}
