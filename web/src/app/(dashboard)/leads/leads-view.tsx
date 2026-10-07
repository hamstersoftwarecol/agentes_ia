"use client";

import Link from "next/link";
import { Fragment, useState } from "react";
import { Alert, Button, Card, cn, Input, Spinner, Textarea } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import { formatDateTime, formatRecent } from "@/lib/format";
import { formatPhone, LEAD_STATUSES } from "@/lib/status";
import type { Lead, LeadStatus, Paginated } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

const REFRESH_MS = 15000;

export function LeadsView() {
  const [status, setStatus] = useState<string>("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const leads = useApi<Paginated<Lead>>(`/leads?page=${page}${status ? `&status=${status}` : ""}`, () => REFRESH_MS);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Leads</h1>
        <p className="mt-1 text-sm text-muted">
          Prospectos que tus agentes captaron en el chat. Muévelos por el embudo: Nuevo → Contactado → Calificado → Convertido.
        </p>
      </div>

      <div className="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por estado">
        {[{ value: "", label: "Todos" }, ...LEAD_STATUSES].map((f) => (
          <button
            key={f.value}
            type="button"
            aria-pressed={status === f.value}
            onClick={() => {
              setStatus(f.value);
              setPage(1);
              setOpen(null);
            }}
            className={cn(
              "rounded-full border border-border px-3 py-1 text-xs hover:bg-brand-soft",
              status === f.value && "border-brand bg-brand-soft font-medium",
            )}
          >
            {f.label}
          </button>
        ))}
      </div>

      {leads.loading && <Spinner className="size-5" />}
      {leads.error && <Alert>{leads.error.message}</Alert>}

      {leads.data?.meta.total === 0 && (
        <Card className="py-12 text-center">
          <p className="font-medium">{status ? "No hay leads con este estado" : "Todavía no hay leads"}</p>
          {!status && (
            <p className="mx-auto mt-1 max-w-md text-sm text-muted">
              Cuando un cliente muestre interés y deje su teléfono o correo en el chat, el agente lo guardará aquí y te avisará por correo.
            </p>
          )}
        </Card>
      )}

      {leads.data && leads.data.data.length > 0 && (
        <Card className="overflow-x-auto p-0">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead className="border-b border-border text-xs text-muted">
              <tr>
                <th className="px-4 py-3 font-medium">Lead</th>
                <th className="px-4 py-3 font-medium">Contacto</th>
                <th className="px-4 py-3 font-medium">Interés</th>
                <th className="px-4 py-3 font-medium">Estado</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {leads.data.data.map((lead) => (
                <Fragment key={lead.id}>
                  <LeadRow
                    lead={lead}
                    expanded={open === lead.id}
                    onToggle={() => setOpen(open === lead.id ? null : lead.id)}
                    onChanged={() => void leads.reload()}
                  />
                  {open === lead.id && (
                    <tr className="border-t border-border bg-background">
                      <td colSpan={5} className="p-4">
                        <LeadDetails lead={lead} onChanged={() => void leads.reload()} onDeleted={() => { setOpen(null); void leads.reload(); }} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {leads.data && leads.data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <Button variant="secondary" className="px-3 py-1.5" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Anterior
          </Button>
          <span className="text-muted">
            {page} / {leads.data.meta.last_page}
          </span>
          <Button variant="secondary" className="px-3 py-1.5" disabled={page >= leads.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
            Siguiente
          </Button>
        </div>
      )}
    </div>
  );
}

function LeadRow({ lead, expanded, onToggle, onChanged }: { lead: Lead; expanded: boolean; onToggle: () => void; onChanged: () => void }) {
  const changeStatus = useAction(async (value: LeadStatus) => {
    await api(`/leads/${lead.id}`, { method: "PATCH", body: { status: value } });
    onChanged();
  });

  return (
    <tr className="border-t border-border align-top first:border-t-0">
      <td className="px-4 py-3">
        <p className="font-medium">{lead.name ?? "Sin nombre"}</p>
        <p className="text-xs text-muted" title={formatDateTime(lead.created_at)}>
          {formatRecent(lead.created_at)}
        </p>
      </td>
      <td className="px-4 py-3">
        {lead.phone && (
          <a className="block underline" href={`tel:${lead.phone}`}>
            {formatPhone(lead.phone)}
          </a>
        )}
        {lead.email && (
          <a className="block break-all underline" href={`mailto:${lead.email}`}>
            {lead.email}
          </a>
        )}
      </td>
      <td className="max-w-[16rem] px-4 py-3 text-muted">{lead.interest ?? "—"}</td>
      <td className="px-4 py-3">
        <select
          aria-label={`Estado de ${lead.name ?? "este lead"}`}
          value={lead.status}
          disabled={changeStatus.pending}
          onChange={(e) => void changeStatus.run(e.target.value as LeadStatus)}
          className="rounded-lg border border-border bg-card px-2 py-1.5 text-sm"
        >
          {LEAD_STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
        {changeStatus.error && <p className="mt-1 text-xs text-danger">{changeStatus.error.message}</p>}
      </td>
      <td className="px-4 py-3 text-right">
        <Button variant="ghost" className="px-3 py-1.5" aria-expanded={expanded} onClick={onToggle}>
          {expanded ? "Cerrar" : "Detalles"}
        </Button>
      </td>
    </tr>
  );
}

function LeadDetails({ lead, onChanged, onDeleted }: { lead: Lead; onChanged: () => void; onDeleted: () => void }) {
  const canManage = useCanManage();
  const [form, setForm] = useState({
    name: lead.name ?? "",
    phone: lead.phone ?? "",
    email: lead.email ?? "",
    interest: lead.interest ?? "",
    notes: lead.notes ?? "",
  });
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const save = useAction(async () => {
    const empty = (v: string) => (v.trim() === "" ? null : v.trim());
    await api(`/leads/${lead.id}`, {
      method: "PATCH",
      body: {
        name: empty(form.name),
        phone: empty(form.phone.replace(/[\s()-]/g, "")),
        email: empty(form.email),
        interest: empty(form.interest),
        notes: empty(form.notes),
      },
    });
    onChanged();
  });
  const remove = useAction(async () => {
    await api(`/leads/${lead.id}`, { method: "DELETE" });
    onDeleted();
  });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void save.run();
      }}
    >
      {save.done && <Alert tone="success">Cambios guardados.</Alert>}
      {save.error && !Object.keys(save.error.errors).length && <Alert>{save.error.message}</Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Nombre" value={form.name} onChange={set("name")} error={save.error?.field("name")} />
        <Input label="Teléfono" type="tel" value={form.phone} onChange={set("phone")} error={save.error?.field("phone")} />
        <Input label="Correo" type="email" value={form.email} onChange={set("email")} error={save.error?.field("email")} />
        <Input label="Interés" value={form.interest} onChange={set("interest")} error={save.error?.field("interest")} />
      </div>
      <Textarea
        label="Notas internas"
        rows={3}
        maxLength={2000}
        placeholder="Qué se habló, cuándo llamar, cotización enviada…"
        value={form.notes}
        onChange={set("notes")}
        error={save.error?.field("notes")}
      />
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" loading={save.pending}>
          Guardar cambios
        </Button>
        {lead.conversation_id && (
          <Link href={`/conversations?c=${lead.conversation_id}`} className="text-sm text-brand hover:underline">
            Ver la conversación
          </Link>
        )}
        {canManage && (
          <Button
            type="button"
            variant="danger"
            className="ml-auto"
            loading={remove.pending}
            onClick={() => {
              if (window.confirm("¿Eliminar este lead? Esta acción no se puede deshacer.")) void remove.run();
            }}
          >
            Eliminar
          </Button>
        )}
      </div>
    </form>
  );
}
