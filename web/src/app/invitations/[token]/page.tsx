import type { Metadata } from "next";
import { Suspense } from "react";
import { InvitationView } from "./invitation-view";

export const metadata: Metadata = { title: "Invitación al equipo" };

// La invitación y la sesión se leen en el cliente (token de la URL y cookie).
export const instant = false;

export default function InvitationPage() {
  return (
    <Suspense>
      <InvitationView />
    </Suspense>
  );
}
