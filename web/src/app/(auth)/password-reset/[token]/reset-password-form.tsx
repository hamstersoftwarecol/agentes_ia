"use client";

import { useParams, useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Alert, Button, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { useAction } from "@/lib/use-action";

export function ResetPasswordForm() {
  const { token } = useParams<{ token: string }>();
  const email = useSearchParams().get("email") ?? "";
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");

  const submit = useAction(async () => {
    await api("/reset-password", {
      method: "POST",
      body: { token, email, password, password_confirmation: confirmation },
    });
    router.replace("/login?reset=1");
  });

  return (
    <AuthShell title="Crea una nueva contraseña" subtitle={email || undefined}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          submit.run();
        }}
      >
        {submit.error && !submit.error.field("password") && <Alert>{submit.error.message}</Alert>}
        <Input
          label="Nueva contraseña"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={submit.error?.field("password")}
        />
        <Input
          label="Confirma la contraseña"
          type="password"
          autoComplete="new-password"
          required
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
        />
        <Button type="submit" loading={submit.pending} className="w-full">
          Guardar contraseña
        </Button>
      </form>
    </AuthShell>
  );
}
