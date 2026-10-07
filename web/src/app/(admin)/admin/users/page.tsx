import type { Metadata } from "next";
import { UsersView } from "@/components/admin/views";

export const metadata: Metadata = { title: "Plataforma · Usuarios" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function AdminUsersViewPage() {
  return <UsersView />;
}
