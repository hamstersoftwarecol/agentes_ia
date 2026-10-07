import type { Metadata } from "next";
import { TeamView } from "./team-view";

export const metadata: Metadata = { title: "Equipo" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function TeamPage() {
  return <TeamView />;
}
