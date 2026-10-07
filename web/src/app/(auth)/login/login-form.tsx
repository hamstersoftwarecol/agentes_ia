"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Alert, Button, Input } from "@/components/ui";
import { homeFor, safeNext, useAuth } from "@/lib/auth";
import { useAction } from "@/lib/use-action";

export function LoginForm() {
  const { login } = useAuth();
  const router = useRouter();
  const justReset = useSearchParams().get("reset") === "1";
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);

  const submit = useAction(async () => {
    const user = await login(email, password, remember);
    if (user) router.replace(safeNext(window.location.search) ?? homeFor(user));
  });

  return (
    <AuthShell
      title="Inicia sesión"
      subtitle="Entra al panel de tus agentes."
      footer={
        <>
          ¿No tienes cuenta?{" "}
          <Link href="/register" className="font-medium text-brand hover:underline">
            Regístrate
          </Link>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          submit.run();
        }}
      >
        {justReset && <Alert tone="success">Contraseña actualizada. Ya puedes iniciar sesión.</Alert>}
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
        <Input
          label="Contraseña"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <div className="flex items-center justify-between text-sm">
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
              className="size-4 accent-[var(--brand)]"
            />
            Recordarme
          </label>
          <Link href="/forgot-password" className="text-brand hover:underline">
            ¿Olvidaste tu contraseña?
          </Link>
        </div>
        <Button type="submit" loading={submit.pending} className="w-full">
          Entrar
        </Button>
      </form>
    </AuthShell>
  );
}
