import type { Metadata } from "next";
import { ProfileView } from "./profile-view";

export const metadata: Metadata = { title: "Mi perfil" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function ProfilePage() {
  return <ProfileView />;
}
