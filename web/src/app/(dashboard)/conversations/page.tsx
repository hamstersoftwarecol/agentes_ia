import type { Metadata } from "next";
import { Suspense } from "react";
import { ConversationsView } from "./conversations-view";

export const metadata: Metadata = { title: "Conversaciones" };

// Contenido detrás de la sesión (cookie), que solo se lee en el cliente.
export const instant = false;

export default function ConversationsPage() {
  // useSearchParams (enlace directo desde el correo: ?c=ID) exige un límite de Suspense.
  return (
    <Suspense>
      <ConversationsView />
    </Suspense>
  );
}
