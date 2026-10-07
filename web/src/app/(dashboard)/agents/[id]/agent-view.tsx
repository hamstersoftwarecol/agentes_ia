"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { AgentForm } from "@/components/agents/AgentForm";
import { AgentTestChat } from "@/components/agents/AgentTestChat";
import { WidgetCard } from "@/components/agents/WidgetCard";
import { Alert, Badge, Button, Card, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import type { Agent } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

export function AgentView() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const canManage = useCanManage();
  const agent = useApi<Agent>(`/agents/${id}`);

  const remove = useAction(async () => {
    await api(`/agents/${id}`, { method: "DELETE" });
    router.replace("/agents");
  });

  if (agent.loading) return <Spinner className="size-5" />;
  if (agent.error || !agent.data) {
    return (
      <div className="space-y-4">
        <Alert>{agent.error?.status === 404 ? "No encontramos este agente." : (agent.error?.message ?? "Error")}</Alert>
        <Link href="/agents" className="text-sm text-brand hover:underline">
          ← Volver a Agentes
        </Link>
      </div>
    );
  }
  const data = agent.data;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/agents" className="text-sm text-muted hover:underline">
          ← Agentes
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{data.name}</h1>
          <Badge tone={data.is_active ? "success" : "neutral"}>{data.is_active ? "Activo" : "Inactivo"}</Badge>
        </div>
        <p className="mt-1 text-xs text-muted">
          ID público: <code>{data.public_id}</code>
        </p>
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <h2 className="mb-4 font-semibold">Configuración</h2>
          <AgentForm agent={data} onSaved={() => void agent.reload()} />
        </Card>

        <div className="space-y-6">
          <AgentTestChat key={`${data.id}-${data.knowledge_base_id}`} agent={data} />

          <WidgetCard key={data.id} agent={data} onSaved={() => void agent.reload()} />

          {canManage && (
            <Card>
              <h2 className="font-semibold">Eliminar agente</h2>
              <p className="mt-1 text-sm text-muted">Se borra el agente. Tu base de conocimiento no se toca.</p>
              {remove.error && (
                <div className="mt-3">
                  <Alert>{remove.error.message}</Alert>
                </div>
              )}
              <Button
                variant="danger"
                className="mt-4"
                loading={remove.pending}
                onClick={() => {
                  if (window.confirm(`¿Eliminar a ${data.name}? Esta acción no se puede deshacer.`)) void remove.run();
                }}
              >
                Eliminar agente
              </Button>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
