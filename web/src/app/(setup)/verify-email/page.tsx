import type { Metadata } from "next";
import { VerifyEmailView } from "./verify-email-view";

export const metadata: Metadata = { title: "Verifica tu correo" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function VerifyEmailPage() {
  return <VerifyEmailView />;
}
