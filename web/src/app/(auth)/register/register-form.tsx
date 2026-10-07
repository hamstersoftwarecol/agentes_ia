"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Alert, Button, Input } from "@/components/ui";
import { homeFor, useAuth } from "@/lib/auth";
import { useAction } from "@/lib/use-action";

export function RegisterForm() {
  const { register } = useAuth();
  const router = useRouter();
  const [form, setForm] = useState({ name: "", email: "", password: "", password_confirmation: "" });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const submit = useAction(async () => {
    const user = await register(form);
    if (user) router.replace(homeFor(user));
  });
  const err = (name: string) => submit.error?.field(name);

  return (
    <AuthShell
      title="Crea tu cuenta"
      subtitle="Prueba tu primer agente de IA en minutos."
      footer={
        <>
          ¿Ya tienes cuenta?{" "}
          <Link href="/login" className="font-medium text-brand hover:underline">
            Inicia sesión
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
        {submit.error && !Object.keys(submit.error.errors).length && <Alert>{submit.error.message}</Alert>}
        <Input label="Tu nombre" autoComplete="name" required value={form.name} onChange={set("name")} error={err("name")} />
        <Input
          label="Correo electrónico"
          type="email"
          autoComplete="email"
          required
          value={form.email}
          onChange={set("email")}
          error={err("email")}
        />
        <Input
          label="Contraseña"
          type="password"
          autoComplete="new-password"
          required
          value={form.password}
          onChange={set("password")}
          error={err("password")}
          hint="Mínimo 8 caracteres."
        />
        <Input
          label="Confirma la contraseña"
          type="password"
          autoComplete="new-password"
          required
          value={form.password_confirmation}
          onChange={set("password_confirmation")}
        />
        <Button type="submit" loading={submit.pending} className="w-full">
          Crear cuenta
        </Button>
      </form>
    </AuthShell>
  );
}
