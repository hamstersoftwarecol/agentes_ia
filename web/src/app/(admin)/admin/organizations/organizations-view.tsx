"use client";

import Link from "next/link";
import { useState } from "react";
import { Pager, Table, Td } from "@/components/admin/Table";
import { Alert, Badge, Button, Input, Spinner } from "@/components/ui";
import { formatDay } from "@/lib/format";
import type { AdminOrganization, BillingState, Paginated } from "@/lib/types";
import { useApi } from "@/lib/use-api";

export const STATE_LABEL: Record<BillingState, { label: string; tone: "success" | "info" | "danger" | "neutral" }> = {
  active: { label: "Activa", tone: "success" },
  trialing: { label: "En prueba", tone: "info" },
  past_due: { label: "Pago pendiente", tone: "danger" },
  expired: { label: "Vencida", tone: "danger" },
  canceled: { label: "Cancelada", tone: "danger" },
  unpaid: { label: "Impaga", tone: "danger" },
  incomplete: { label: "Incompleta", tone: "danger" },
  suspended: { label: "Suspendida", tone: "danger" },
  none: { label: "Sin plan", tone: "neutral" },
};

export function OrganizationsView() {
  const [search, setSearch] = useState("");
  const [applied, setApplied] = useState("");
  const [page, setPage] = useState(1);
  const orgs = useApi<Paginated<AdminOrganization>>(`/admin/organizations?page=${page}${applied ? `&search=${encodeURIComponent(applied)}` : ""}`);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">Empresas</h1>

      <form
        className="flex max-w-md items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          setApplied(search.trim());
          setPage(1);
        }}
      >
        <div className="flex-1">
          <Input label="Buscar por nombre" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Barbería…" />
        </div>
        <Button type="submit" variant="secondary">
          Buscar
        </Button>
      </form>

      {orgs.loading && <Spinner className="size-5" />}
      {orgs.error && <Alert>{orgs.error.message}</Alert>}
      {orgs.data && (
        <>
          <Table head={["Empresa", "Dueño", "Plan", "Estado", ">Mensajes", ">Agentes", "Creada"]}>
            {orgs.data.data.map((o) => {
              const state = STATE_LABEL[o.state];
              return (
                <tr key={o.id}>
                  <Td>
                    <Link href={`/admin/organizations/${o.id}`} className="font-medium hover:underline">
                      {o.name}
                    </Link>
                  </Td>
                  <Td>
                    <p>{o.owner?.name ?? "—"}</p>
                    <p className="text-xs text-muted">{o.owner?.email}</p>
                  </Td>
                  <Td>
                    {o.plan?.name ?? "—"}
                    {o.source === "manual" && <span className="ml-1 text-xs text-muted">(cortesía)</span>}
                  </Td>
                  <Td>
                    <Badge tone={state.tone}>{state.label}</Badge>
                  </Td>
                  <Td right>
                    {o.messages_used} / {o.messages_limit}
                  </Td>
                  <Td right>{o.agents_count}</Td>
                  <Td className="whitespace-nowrap">{formatDay(o.created_at)}</Td>
                </tr>
              );
            })}
          </Table>
          {orgs.data.data.length === 0 && <p className="text-sm text-muted">No hay empresas que coincidan.</p>}
          <Pager page={page} last={orgs.data.meta.last_page} onPage={setPage} />
        </>
      )}
    </div>
  );
}
