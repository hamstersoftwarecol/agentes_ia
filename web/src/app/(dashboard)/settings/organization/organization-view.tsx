"use client";

import { useRef, useState } from "react";
import { TimezoneSelect } from "@/components/TimezoneSelect";
import { Alert, Button, Card, Input, Select } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useAction } from "@/lib/use-action";
import { DAY_LABELS, DAYS, type BusinessHours, type Organization } from "@/lib/types";

// Países de operación habituales; el nombre sale de Intl en español.
const COUNTRIES = ["CO", "MX", "AR", "CL", "PE", "EC", "UY", "BO", "PY", "VE", "CR", "PA", "DO", "GT", "ES", "US"];

export function OrganizationView() {
  const { user } = useAuth();
  const organization = user!.organization!;
  const canEdit = user!.role === "owner" || user!.role === "admin";

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Empresa</h1>
        {!canEdit && (
          <div className="mt-3">
            <Alert tone="info">Solo el propietario o un administrador puede cambiar estos datos.</Alert>
          </div>
        )}
      </div>
      <LogoCard organization={organization} canEdit={canEdit} />
      <DetailsCard organization={organization} canEdit={canEdit} />
      <HoursCard organization={organization} canEdit={canEdit} />
    </div>
  );
}

function LogoCard({ organization, canEdit }: { organization: Organization; canEdit: boolean }) {
  const { refresh } = useAuth();
  const input = useRef<HTMLInputElement>(null);

  const upload = useAction(async (file: File) => {
    const body = new FormData();
    body.append("logo", file);
    await api("/organization/logo", { method: "POST", body });
    await refresh();
  });
  const remove = useAction(async () => {
    await api("/organization/logo", { method: "DELETE" });
    await refresh();
  });
  const error = upload.error ?? remove.error;

  return (
    <Card>
      <h2 className="font-semibold">Logo</h2>
      <div className="mt-4 flex items-center gap-5">
        {organization.logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={organization.logo_url} alt={`Logo de ${organization.name}`} className="size-20 rounded-xl border border-border object-cover" />
        ) : (
          <span className="grid size-20 place-items-center rounded-xl bg-brand-soft text-2xl font-semibold">
            {organization.name.charAt(0).toUpperCase()}
          </span>
        )}
        <div className="space-y-2">
          <input
            ref={input}
            type="file"
            accept="image/png,image/jpeg,image/webp"
            hidden
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) void upload.run(file);
              e.target.value = "";
            }}
          />
          <div className="flex gap-2">
            <Button variant="secondary" disabled={!canEdit} loading={upload.pending} onClick={() => input.current?.click()}>
              {organization.logo_url ? "Cambiar logo" : "Subir logo"}
            </Button>
            {organization.logo_url && (
              <Button variant="danger" disabled={!canEdit} loading={remove.pending} onClick={() => void remove.run()}>
                Quitar
              </Button>
            )}
          </div>
          <p className="text-xs text-muted">PNG, JPG o WebP. Máximo 2 MB.</p>
        </div>
      </div>
      {error && (
        <div className="mt-4">
          <Alert>{error.field("logo") ?? error.message}</Alert>
        </div>
      )}
    </Card>
  );
}

function DetailsCard({ organization, canEdit }: { organization: Organization; canEdit: boolean }) {
  const { refresh } = useAuth();
  const [form, setForm] = useState({
    name: organization.name,
    website: organization.website ?? "",
    email: organization.email ?? "",
    phone: organization.phone ?? "",
    address: organization.address ?? "",
    country: organization.country ?? "",
    timezone: organization.timezone,
  });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const save = useAction(async () => {
    const empty = (v: string) => (v.trim() === "" ? null : v.trim());
    await api("/organization", {
      method: "PATCH",
      body: {
        name: form.name,
        timezone: form.timezone,
        website: empty(form.website),
        email: empty(form.email),
        phone: empty(form.phone),
        address: empty(form.address),
        country: empty(form.country),
      },
    });
    await refresh();
  });
  const err = (name: string) => save.error?.field(name);
  const regionNames = new Intl.DisplayNames(["es"], { type: "region" });

  return (
    <Card>
      <h2 className="font-semibold">Datos de la empresa</h2>
      <form
        className="mt-4 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save.run();
        }}
      >
        <fieldset disabled={!canEdit} className="space-y-4">
          {save.error && !Object.keys(save.error.errors).length && <Alert>{save.error.message}</Alert>}
          {save.done && <Alert tone="success">Cambios guardados.</Alert>}
          <Input label="Nombre" required maxLength={120} value={form.name} onChange={set("name")} error={err("name")} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Sitio web"
              type="url"
              placeholder="https://miempresa.com"
              value={form.website}
              onChange={set("website")}
              error={err("website")}
            />
            <Input label="Correo de contacto" type="email" value={form.email} onChange={set("email")} error={err("email")} />
            <Input label="Teléfono" type="tel" value={form.phone} onChange={set("phone")} error={err("phone")} />
            <Select label="País" value={form.country} onChange={set("country")} error={err("country")}>
              <option value="">Sin especificar</option>
              {COUNTRIES.map((code) => (
                <option key={code} value={code}>
                  {regionNames.of(code)}
                </option>
              ))}
            </Select>
          </div>
          <Input label="Dirección" value={form.address} onChange={set("address")} error={err("address")} />
          <TimezoneSelect
            value={form.timezone}
            onChange={(timezone) => setForm((f) => ({ ...f, timezone }))}
            error={err("timezone")}
            disabled={!canEdit}
          />
          <Button type="submit" loading={save.pending}>
            Guardar cambios
          </Button>
        </fieldset>
      </form>
    </Card>
  );
}

function HoursCard({ organization, canEdit }: { organization: Organization; canEdit: boolean }) {
  const { refresh } = useAuth();
  const [hours, setHours] = useState<BusinessHours>(organization.business_hours);

  const update = (day: (typeof DAYS)[number], patch: Partial<BusinessHours[typeof day]>) =>
    setHours((h) => ({ ...h, [day]: { ...h[day], ...patch } }));

  const save = useAction(async () => {
    await api("/organization/business-hours", { method: "PUT", body: { business_hours: hours } });
    await refresh();
  });

  return (
    <Card>
      <h2 className="font-semibold">Horario de atención</h2>
      <p className="mt-1 text-sm text-muted">
        Tus agentes podrán informar a los clientes cuándo atiende una persona. Zona horaria: {organization.timezone}.
      </p>
      <form
        className="mt-4 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save.run();
        }}
      >
        <fieldset disabled={!canEdit} className="space-y-4">
          {save.done && <Alert tone="success">Horario guardado.</Alert>}
          {save.error && <Alert>{save.error.message}</Alert>}
          <ul className="divide-y divide-border">
            {DAYS.map((day) => {
              const row = hours[day];
              const rowError = save.error?.field(`business_hours.${day}.close`);
              return (
                <li key={day} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                  <label className="flex w-36 items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={row.enabled}
                      onChange={(e) => update(day, { enabled: e.target.checked })}
                      className="size-4 accent-[var(--brand)]"
                    />
                    {DAY_LABELS[day]}
                  </label>
                  {row.enabled ? (
                    <div className="flex items-center gap-2 text-sm">
                      <input
                        type="time"
                        aria-label={`${DAY_LABELS[day]}: apertura`}
                        value={row.open}
                        onChange={(e) => update(day, { open: e.target.value })}
                        className="rounded-lg border border-border bg-card px-2 py-1.5"
                      />
                      <span className="text-muted">a</span>
                      <input
                        type="time"
                        aria-label={`${DAY_LABELS[day]}: cierre`}
                        value={row.close}
                        onChange={(e) => update(day, { close: e.target.value })}
                        className="rounded-lg border border-border bg-card px-2 py-1.5"
                      />
                    </div>
                  ) : (
                    <span className="text-sm text-muted">Cerrado</span>
                  )}
                  {rowError && <span className="text-xs text-danger">El cierre debe ser posterior a la apertura.</span>}
                </li>
              );
            })}
          </ul>
          <Button type="submit" loading={save.pending}>
            Guardar horario
          </Button>
        </fieldset>
      </form>
    </Card>
  );
}
