"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { RouteGuard } from "@/components/RouteGuard";
import { TimezoneSelect } from "@/components/TimezoneSelect";
import { Alert, Button, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useAction } from "@/lib/use-action";

export function OnboardingView() {
  return (
    <RouteGuard rule="no-org">
      <OnboardingForm />
    </RouteGuard>
  );
}

function OnboardingForm() {
  const { refresh, logout } = useAuth();
  const router = useRouter();
  const [name, setName] = useState("");
  const [timezone, setTimezone] = useState(() => Intl.DateTimeFormat().resolvedOptions().timeZone);

  const submit = useAction(async () => {
    await api("/organization", { method: "POST", body: { name, timezone } });
    await refresh();
    router.replace("/dashboard");
  });

  return (
    <AuthShell
      title="Cuéntanos sobre tu empresa"
      subtitle="Tus agentes hablarán en nombre de ella. Podrás completar el resto después."
      footer={
        <button type="button" onClick={() => void logout()} className="font-medium text-brand hover:underline">
          Cerrar sesión
        </button>
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
        <Input
          label="Nombre de la empresa"
          required
          maxLength={120}
          placeholder="Barbería XYZ"
          value={name}
          onChange={(e) => setName(e.target.value)}
          error={submit.error?.field("name")}
        />
        <TimezoneSelect value={timezone} onChange={setTimezone} error={submit.error?.field("timezone")} />
        <Button type="submit" loading={submit.pending} className="w-full">
          Continuar
        </Button>
      </form>
    </AuthShell>
  );
}
