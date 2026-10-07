"use client";

import { useEffect } from "react";
import { AuthShell } from "@/components/AuthShell";
import { RouteGuard } from "@/components/RouteGuard";
import { Alert, Button } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useAction } from "@/lib/use-action";

export function VerifyEmailView() {
  return (
    <RouteGuard rule="unverified">
      <VerifyEmailContent />
    </RouteGuard>
  );
}

function VerifyEmailContent() {
  const { user, refresh, logout } = useAuth();

  // Al volver de abrir el enlace del correo en otra pestaña, avanzamos solos.
  useEffect(() => {
    const onFocus = () => void refresh().catch(() => {});
    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, [refresh]);

  const resend = useAction(() => api("/email/verification-notification", { method: "POST" }));
  const check = useAction(async () => {
    await refresh();
  });

  return (
    <AuthShell
      title="Verifica tu correo"
      subtitle={`Te enviamos un enlace a ${user?.email}. Ábrelo para activar tu cuenta.`}
      footer={
        <button type="button" onClick={() => void logout()} className="font-medium text-brand hover:underline">
          Cerrar sesión
        </button>
      }
    >
      <div className="space-y-4">
        {resend.done && <Alert tone="success">Te enviamos un nuevo enlace.</Alert>}
        {resend.error && <Alert>{resend.error.status === 429 ? "Espera un momento antes de reenviar." : resend.error.message}</Alert>}
        <Button className="w-full" loading={check.pending} onClick={() => void check.run()}>
          Ya verifiqué mi correo
        </Button>
        <Button variant="secondary" className="w-full" loading={resend.pending} onClick={() => void resend.run()}>
          Reenviar enlace
        </Button>
        {process.env.NODE_ENV === "development" && (
          <p className="text-xs text-muted">
            Desarrollo: el correo se escribe en <code>api/storage/logs/laravel.log</code>.
          </p>
        )}
      </div>
    </AuthShell>
  );
}
