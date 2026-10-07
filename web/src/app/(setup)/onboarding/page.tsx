import type { Metadata } from "next";
import { OnboardingView } from "./onboarding-view";

export const metadata: Metadata = { title: "Crea tu empresa" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function OnboardingPage() {
  return <OnboardingView />;
}
