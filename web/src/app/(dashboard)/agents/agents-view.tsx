"use client";

import Link from "next/link";
import { PlanLimitAlert } from "@/components/BillingProvider";
import { Alert, Badge, Card, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import type { Agent } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

export function AgentsView() {
  const canManage = useCanManage();
  const agents = useApi<Agent[]>("/agents");

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Agentes</h1>
          <p className="mt-1 text-sm text-muted">Los asistentes de IA que atienden a tus clientes.</p>
        </div>
        {canManage && (
          <Link
            href="/agents/new"
            className="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-hover dark:text-stone-950"
          >
            Nuevo agente
          </Link>
        )}
      </div>

      {agents.loading && <Spinner className="size-5" />}
      {agents.error && <Alert>{agents.error.message}</Alert>}

      {agents.data?.length === 0 && (
        <Card className="py-12 text-center">
          <p className="font-medium">Aún no tienes agentes</p>
          <p className="mx-auto mt-1 max-w-sm text-sm text-muted">
            Crea tu primer agente, dale un nombre y una personalidad, y conéctalo a tu base de conocimiento.
          </p>
          {canManage && (
            <Link href="/agents/new" className="mt-5 inline-block text-sm font-medium text-brand hover:underline">
              Crear mi primer agente
            </Link>
          )}
        </Card>
      )}

      <ul className="grid gap-4 sm:grid-cols-2">
        {agents.data?.map((agent) => (
          <AgentCard key={agent.id} agent={agent} canManage={canManage} onChanged={agents.reload} />
        ))}
      </ul>
    </div>
  );
}

function AgentCard({ agent, canManage, onChanged }: { agent: Agent; canManage: boolean; onChanged: () => void }) {
  const toggle = useAction(async () => {
    await api(`/agents/${agent.id}`, { method: "PATCH", body: { is_active: !agent.is_active } });
    onChanged();
  });

  return (
    <li>
      <Card className="flex h-full flex-col gap-3 p-5">
        <div className="flex items-start justify-between gap-3">
          <Link href={`/agents/${agent.id}`} className="min-w-0 hover:underline">
            <h2 className="truncate font-semibold">{agent.name}</h2>
            {agent.role && <p className="truncate text-sm text-muted">{agent.role}</p>}
          </Link>
          <button
            type="button"
            role="switch"
            aria-checked={agent.is_active}
            aria-label={`${agent.is_active ? "Desactivar" : "Activar"} a ${agent.name}`}
            disabled={!canManage || toggle.pending}
            onClick={() => void toggle.run()}
            className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-60 ${
              agent.is_active ? "bg-brand" : "bg-border"
            }`}
          >
            <span
              className={`absolute top-0.5 size-5 rounded-full bg-white transition-all ${
                agent.is_active ? "left-[1.375rem]" : "left-0.5"
              }`}
            />
          </button>
        </div>
        <div className="mt-auto flex flex-wrap items-center gap-2">
          <Badge tone={agent.is_active ? "success" : "neutral"}>{agent.is_active ? "Activo" : "Inactivo"}</Badge>
          {agent.knowledge_base ? (
            <Badge tone="info">{agent.knowledge_base.name}</Badge>
          ) : (
            <Badge tone="danger">Sin conocimiento</Badge>
          )}
        </div>
        {toggle.error && <PlanLimitAlert error={toggle.error} />}
      </Card>
    </li>
  );
}
