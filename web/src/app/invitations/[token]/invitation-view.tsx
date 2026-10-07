"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Alert, Button, Input, Spinner } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { ROLE_LABELS, useAuth } from "@/lib/auth";
import type { InvitationPreview } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

/** Lo que ve quien abre el enlace de una invitación: se une con su cuenta, o la crea ahí mismo. */
export function InvitationView() {
  const { token } = useParams<{ token: string }>();
  const { user, status, refresh, logout } = useAuth();
  const router = useRouter();
  const preview = useApi<InvitationPreview>(`/invitations/${token}`);

  if (preview.loading || status === "loading") {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Spinner className="size-6" />
      </div>
    );
  }

  if (preview.error || !preview.data) {
    const status410 = preview.error?.status === 410;
    return (
      <AuthShell
        title={status410 ? "Esta invitación venció" : "No encontramos la invitación"}
        subtitle={status410 ? "Pide a quien te invitó que te envíe una nueva." : "El enlace no existe o ya se usó."}
        footer={
          <Link href="/login" className="font-medium text-brand hover:underline">
            Ir a iniciar sesión
          </Link>
        }
      >
        {preview.error && preview.error.status !== 404 && preview.error.status !== 410 && <Alert>{preview.error.message}</Alert>}
      </AuthShell>
    );
  }

  const invitation = preview.data;
  const joined = async () => {
    await refresh();
    router.replace("/dashboard");
  };

  return (
    <AuthShell
      title={`Únete a ${invitation.organization.name}`}
      subtitle={`${invitation.invited_by ?? "Te"} te invitó como ${ROLE_LABELS[invitation.role].toLowerCase()}.`}
    >
      {invitation.organization.logo_url && (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={invitation.organization.logo_url} alt="" className="mb-4 size-12 rounded-lg object-cover" />
      )}

      {!user && invitation.account_exists && (
        <div className="space-y-4">
          <p className="text-sm">
            La invitación es para <strong>{invitation.email}</strong>, que ya tiene cuenta. Inicia sesión con ese correo para aceptarla.
          </p>
          <Link
            href={`/login?next=${encodeURIComponent(`/invitations/${token}`)}`}
            className="inline-flex w-full items-center justify-center rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-hover dark:text-stone-950"
          >
            Iniciar sesión
          </Link>
        </div>
      )}

      {!user && !invitation.account_exists && <RegisterForm token={token} email={invitation.email} onDone={joined} />}

      {user && user.email.toLowerCase() !== invitation.email && (
        <div className="space-y-4">
          <p className="text-sm">
            Entraste como <strong>{user.email}</strong>, pero la invitación es para <strong>{invitation.email}</strong>.
          </p>
          <Button variant="secondary" className="w-full" onClick={() => void logout()}>
            Cerrar sesión y continuar
          </Button>
        </div>
      )}

      {user && user.email.toLowerCase() === invitation.email && !user.email_verified && (
        <div className="space-y-4">
          <p className="text-sm">Antes de unirte, verifica tu correo con el enlace que te enviamos.</p>
          <Link href="/verify-email" className="font-medium text-brand hover:underline">
            Ir a verificar mi correo
          </Link>
        </div>
      )}

      {user && user.email.toLowerCase() === invitation.email && user.email_verified && (
        <AcceptButton token={token} organization={invitation.organization.name} onDone={joined} />
      )}
    </AuthShell>
  );
}

/** El error de «ya no hay plazas» habla del plan de la empresa: a quien se une se le dice a quién acudir. */
function describe(error: ApiError, organization: string) {
  return error.code === "plan_limit"
    ? `El equipo de ${organization} ya no tiene plazas disponibles. Pide a quien te invitó que lo resuelva.`
    : error.message;
}

function AcceptButton({ token, organization, onDone }: { token: string; organization: string; onDone: () => Promise<void> }) {
  const accept = useAction(async () => {
    await api(`/invitations/${token}/accept`, { method: "POST" });
    await onDone();
  });

  return (
    <div className="space-y-4">
      {accept.error && <Alert>{describe(accept.error, organization)}</Alert>}
      <Button className="w-full" loading={accept.pending} onClick={() => void accept.run()}>
        Unirme a {organization}
      </Button>
    </div>
  );
}

function RegisterForm({ token, email, onDone }: { token: string; email: string; onDone: () => Promise<void> }) {
  const [form, setForm] = useState({ name: "", password: "", password_confirmation: "" });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const register = useAction(async () => {
    await api(`/invitations/${token}/register`, { method: "POST", body: form });
    await onDone();
  });
  const err = (name: string) => register.error?.field(name);

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void register.run();
      }}
    >
      {register.error && !Object.keys(register.error.errors).length && <Alert>{register.error.message}</Alert>}
      <Input label="Correo electrónico" type="email" value={email} readOnly disabled hint="Es el correo al que llegó la invitación." />
      <Input label="Tu nombre" autoComplete="name" required value={form.name} onChange={set("name")} error={err("name")} />
      <Input label="Contraseña" type="password" autoComplete="new-password" required value={form.password} onChange={set("password")} error={err("password")} />
      <Input
        label="Confirma la contraseña"
        type="password"
        autoComplete="new-password"
        required
        value={form.password_confirmation}
        onChange={set("password_confirmation")}
      />
      <Button type="submit" loading={register.pending} className="w-full">
        Crear cuenta y unirme
      </Button>
    </form>
  );
}
