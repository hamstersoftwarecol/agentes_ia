"use client";

import { useState } from "react";
import { Alert, Button, Card, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useAction } from "@/lib/use-action";

export function ProfileView() {
  return (
    <div className="space-y-8">
      <h1 className="text-2xl font-semibold tracking-tight">Mi perfil</h1>
      <ProfileForm />
      <PasswordForm />
    </div>
  );
}

function ProfileForm() {
  const { user, refresh } = useAuth();
  const [form, setForm] = useState({ name: user!.name, email: user!.email, phone: user!.phone ?? "" });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const save = useAction(async () => {
    await api("/user/profile", { method: "PATCH", body: { ...form, phone: form.phone || null } });
    await refresh();
  });
  const emailChanged = form.email !== user!.email;

  return (
    <Card>
      <h2 className="font-semibold">Datos personales</h2>
      <form
        className="mt-4 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save.run();
        }}
      >
        {save.error && !Object.keys(save.error.errors).length && <Alert>{save.error.message}</Alert>}
        {save.done && <Alert tone="success">Cambios guardados.</Alert>}
        <Input label="Nombre" required value={form.name} onChange={set("name")} error={save.error?.field("name")} />
        <Input
          label="Correo electrónico"
          type="email"
          required
          value={form.email}
          onChange={set("email")}
          error={save.error?.field("email")}
          hint={emailChanged ? "Si cambias el correo, tendrás que verificarlo de nuevo." : undefined}
        />
        <Input
          label="Teléfono"
          type="tel"
          autoComplete="tel"
          value={form.phone}
          onChange={set("phone")}
          error={save.error?.field("phone")}
        />
        <Button type="submit" loading={save.pending}>
          Guardar cambios
        </Button>
      </form>
    </Card>
  );
}

function PasswordForm() {
  const [form, setForm] = useState({ current_password: "", password: "", password_confirmation: "" });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const save = useAction(async () => {
    await api("/user/password", { method: "PUT", body: form });
    setForm({ current_password: "", password: "", password_confirmation: "" });
  });

  return (
    <Card>
      <h2 className="font-semibold">Cambiar contraseña</h2>
      <form
        className="mt-4 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save.run();
        }}
      >
        {save.error && !Object.keys(save.error.errors).length && <Alert>{save.error.message}</Alert>}
        {save.done && <Alert tone="success">Contraseña actualizada.</Alert>}
        <Input
          label="Contraseña actual"
          type="password"
          autoComplete="current-password"
          required
          value={form.current_password}
          onChange={set("current_password")}
          error={save.error?.field("current_password")}
        />
        <Input
          label="Nueva contraseña"
          type="password"
          autoComplete="new-password"
          required
          value={form.password}
          onChange={set("password")}
          error={save.error?.field("password")}
        />
        <Input
          label="Confirma la nueva contraseña"
          type="password"
          autoComplete="new-password"
          required
          value={form.password_confirmation}
          onChange={set("password_confirmation")}
        />
        <Button type="submit" loading={save.pending}>
          Cambiar contraseña
        </Button>
      </form>
    </Card>
  );
}
