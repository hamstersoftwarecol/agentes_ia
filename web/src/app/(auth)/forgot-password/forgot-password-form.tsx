"use client";

import Link from "next/link";
import { useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Alert, Button, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { useAction } from "@/lib/use-action";

export function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [status, setStatus] = useState<string | null>(null);

  const submit = useAction(async () => {
    const res = await api<{ status: string }>("/forgot-password", { method: "POST", body: { email } });
    setStatus(res.status);
  });

  return (
    <AuthShell
      title="Recupera tu contraseña"
      subtitle="Te enviaremos un enlace para crear una nueva."
      footer={
        <Link href="/login" className="font-medium text-brand hover:underline">
          Volver a iniciar sesión
        </Link>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          submit.run();
        }}
      >
        {status && <Alert tone="success">{status}</Alert>}
        {submit.error && !submit.error.field("email") && <Alert>{submit.error.message}</Alert>}
        <Input
          label="Correo electrónico"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          error={submit.error?.field("email")}
        />
        <Button type="submit" loading={submit.pending} className="w-full">
          Enviar enlace
        </Button>
      </form>
    </AuthShell>
  );
}
