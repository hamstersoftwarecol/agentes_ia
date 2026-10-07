"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { PlanLimitAlert, useBilling } from "@/components/BillingProvider";
import { StatusPill } from "@/components/StatusPill";
import { Alert, Badge, Button, Card, cn, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { formatDateTime, formatRecent } from "@/lib/format";
import { formatPhone, leadStatusLabel } from "@/lib/status";
import type {
  Agent,
  Alerts,
  ConversationDetail,
  ConversationMessage,
  ConversationSummary,
  Paginated,
} from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

const REFRESH_MS = 5000;
const keepRefreshing = () => REFRESH_MS;

const FILTERS = [
  { value: "open", label: "Abiertas" },
  { value: "waiting", label: "Esperando" },
  { value: "human", label: "Con una persona" },
  { value: "ai", label: "Con la IA" },
  { value: "closed", label: "Cerradas" },
  { value: "", label: "Todas" },
] as const;

export function ConversationsView() {
  const initial = Number(useSearchParams().get("c")) || null;
  const [page, setPage] = useState(1);
  const [agentId, setAgentId] = useState("");
  const [status, setStatus] = useState<string>(initial ? "" : "open");
  const [selected, setSelected] = useState<number | null>(initial);

  const agents = useApi<Agent[]>("/agents");
  const alerts = useApi<Alerts>("/dashboard/alerts", keepRefreshing);
  const query = `/conversations?page=${page}${agentId ? `&agent_id=${agentId}` : ""}${status ? `&status=${status}` : ""}`;
  const list = useApi<Paginated<ConversationSummary>>(query, keepRefreshing);

  const hasAny = (list.data?.meta.total ?? 0) > 0 || status !== "" || agentId !== "";

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Conversaciones</h1>
        <p className="mt-1 text-sm text-muted">
          Lo que tus clientes hablan con tus agentes. Cuando piden una persona, la IA calla y aparece aquí como «Esperando».
        </p>
      </div>

      {list.loading && <Spinner className="size-5" />}
      {list.error && <Alert>{list.error.message}</Alert>}

      {list.data?.meta.total === 0 && status === "open" && !agentId && !initial && <EmptyState hasAgents={(agents.data?.length ?? 0) > 0} />}

      {list.data && hasAny && !(list.data.meta.total === 0 && status === "open" && !agentId && !initial) && (
        <div className="grid gap-4 xl:grid-cols-[22rem_1fr]">
          {/* En pantallas chicas se ve la lista o el detalle, no ambos. */}
          <div className={cn("space-y-3", selected !== null && "hidden xl:block")}>
            <div className="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por estado">
              {FILTERS.map((f) => (
                <button
                  key={f.value}
                  type="button"
                  aria-pressed={status === f.value}
                  onClick={() => {
                    setStatus(f.value);
                    setPage(1);
                  }}
                  className={cn(
                    "rounded-full border border-border px-3 py-1 text-xs hover:bg-brand-soft",
                    status === f.value && "border-brand bg-brand-soft font-medium",
                  )}
                >
                  {f.label}
                  {f.value === "waiting" && (alerts.data?.waiting_conversations ?? 0) > 0 && (
                    <span className="ml-1.5 rounded-full bg-brand px-1.5 text-[10px] text-white dark:text-stone-950">
                      {alerts.data?.waiting_conversations}
                    </span>
                  )}
                </button>
              ))}
            </div>

            {(agents.data?.length ?? 0) > 1 && (
              <select
                aria-label="Filtrar por agente"
                value={agentId}
                onChange={(e) => {
                  setAgentId(e.target.value);
                  setPage(1);
                }}
                className="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm"
              >
                <option value="">Todos los agentes</option>
                {agents.data?.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </select>
            )}

            {list.data.data.length === 0 && <p className="text-sm text-muted">No hay conversaciones con este filtro.</p>}

            <ul className="space-y-2">
              {list.data.data.map((c) => (
                <li key={c.id}>
                  <button
                    type="button"
                    onClick={() => setSelected(c.id)}
                    aria-current={selected === c.id ? "true" : undefined}
                    className={cn(
                      "w-full rounded-xl border border-border bg-card p-3 text-left transition-colors hover:bg-brand-soft",
                      selected === c.id && "border-brand bg-brand-soft",
                      c.status === "waiting" && selected !== c.id && "border-brand/60",
                    )}
                  >
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="truncate font-medium">{c.contact.name}</span>
                      {c.last_message_at && <span className="shrink-0 text-xs text-muted">{formatRecent(c.last_message_at)}</span>}
                    </div>
                    <p className="mt-0.5 line-clamp-2 text-sm text-muted">
                      {c.last_message?.role === "assistant" && "🤖 "}
                      {c.last_message?.role === "human" && "👤 "}
                      {c.last_message?.preview}
                    </p>
                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                      <StatusPill status={c.status} />
                      {c.lead && <Badge tone="success">Lead</Badge>}
                      <span className="text-xs text-muted">
                        {c.agent_name} · {c.message_count} mensajes
                      </span>
                    </div>
                  </button>
                </li>
              ))}
            </ul>

            {list.data.meta.last_page > 1 && (
              <div className="flex items-center justify-between text-sm">
                <Button variant="secondary" className="px-3 py-1.5" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  Anterior
                </Button>
                <span className="text-muted">
                  {page} / {list.data.meta.last_page}
                </span>
                <Button
                  variant="secondary"
                  className="px-3 py-1.5"
                  disabled={page >= list.data.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Siguiente
                </Button>
              </div>
            )}
          </div>

          <div className={cn(selected === null && "hidden xl:block")}>
            {selected === null ? (
              <Card className="py-16 text-center text-sm text-muted">Elige una conversación para verla.</Card>
            ) : (
              <ConversationThread key={selected} id={selected} onBack={() => setSelected(null)} onChanged={() => void list.reload()} />
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function EmptyState({ hasAgents }: { hasAgents: boolean }) {
  return (
    <Card className="py-12 text-center">
      <p className="font-medium">No hay conversaciones abiertas</p>
      <p className="mx-auto mt-1 max-w-md text-sm text-muted">
        {hasAgents
          ? "Instala el widget en tu sitio web (o pruébalo desde la página de tu agente) y las conversaciones aparecerán aquí."
          : "Primero crea un agente; después podrás instalar su chat en tu sitio web."}
      </p>
      <Link href={hasAgents ? "/agents" : "/agents/new"} className="mt-5 inline-block text-sm font-medium text-brand hover:underline">
        {hasAgents ? "Ir a mis agentes" : "Crear un agente"}
      </Link>
    </Card>
  );
}

function ConversationThread({ id, onBack, onChanged }: { id: number; onBack: () => void; onChanged: () => void }) {
  const conversation = useApi<ConversationDetail>(`/conversations/${id}`, keepRefreshing);
  const { billing } = useBilling();
  // Sin atención humana en el plan (Starter) se puede leer y cerrar, pero no tomar ni responder.
  const humanInbox = billing?.plan?.features.human_inbox ?? true;
  const [draft, setDraft] = useState("");
  const end = useRef<HTMLDivElement>(null);
  const count = conversation.data?.messages.length ?? 0;

  useEffect(() => {
    end.current?.scrollIntoView({ block: "end" });
  }, [count]);

  const refresh = () => {
    void conversation.reload();
    onChanged();
  };
  const take = useAction(async () => {
    await api(`/conversations/${id}/take`, { method: "POST" });
    refresh();
  });
  const release = useAction(async () => {
    await api(`/conversations/${id}/release`, { method: "POST" });
    refresh();
  });
  const close = useAction(async () => {
    await api(`/conversations/${id}/close`, { method: "POST" });
    refresh();
  });
  const reply = useAction(async () => {
    await api(`/conversations/${id}/messages`, { method: "POST", body: { content: draft } });
    setDraft("");
    refresh();
  });

  if (conversation.loading) return <Spinner className="size-5" />;
  if (conversation.error || !conversation.data) return <Alert>{conversation.error?.message ?? "No encontramos esta conversación."}</Alert>;
  const c = conversation.data;
  const error = take.error ?? release.error ?? close.error ?? reply.error;

  return (
    <Card className="p-0">
      <div className="space-y-3 border-b border-border p-4">
        <button type="button" onClick={onBack} className="text-sm text-muted hover:underline xl:hidden">
          ← Conversaciones
        </button>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <h2 className="font-semibold">{c.contact.name}</h2>
          <StatusPill status={c.status} />
          <Badge tone="info">{c.agent_name}</Badge>
        </div>
        <p className="text-xs text-muted">
          Iniciada {formatDateTime(c.created_at)} · {c.message_count} mensajes
          {c.assigned_to && <> · Atiende {c.assigned_to.name}</>}
          {c.page_url && (
            <>
              {" "}
              · desde <span className="break-all">{c.page_url}</span>
            </>
          )}
        </p>

        {c.lead && (
          <div className="rounded-lg border border-border bg-background p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-medium">Lead · {leadStatusLabel(c.lead.status)}</span>
              <Link href="/leads" className="text-xs text-brand hover:underline">
                Ver en Leads
              </Link>
            </div>
            <dl className="mt-1.5 grid gap-x-4 gap-y-0.5 text-muted sm:grid-cols-2">
              {c.lead.name && <div>Nombre: <span className="text-foreground">{c.lead.name}</span></div>}
              {c.lead.phone && (
                <div>
                  Teléfono: <a className="text-foreground underline" href={`tel:${c.lead.phone}`}>{formatPhone(c.lead.phone)}</a>
                </div>
              )}
              {c.lead.email && (
                <div>
                  Correo: <a className="text-foreground underline" href={`mailto:${c.lead.email}`}>{c.lead.email}</a>
                </div>
              )}
              {c.lead.interest && <div className="sm:col-span-2">Interés: <span className="text-foreground">{c.lead.interest}</span></div>}
            </dl>
          </div>
        )}

        {!humanInbox && (
          <Alert tone="info">
            La atención humana en el chat está disponible desde el plan Business: aquí puedes leer las conversaciones y marcarlas como resueltas.{" "}
            <Link href="/billing" className="font-medium underline">
              Ver planes
            </Link>
          </Alert>
        )}

        <div className="flex flex-wrap gap-2">
          {humanInbox && c.status !== "human" && (
            <Button className="px-3 py-1.5" loading={take.pending} onClick={() => void take.run()}>
              {c.status === "closed" ? "Reabrir y atender" : "Tomar la conversación"}
            </Button>
          )}
          {humanInbox && (c.status === "waiting" || c.status === "human") && (
            <Button variant="secondary" className="px-3 py-1.5" loading={release.pending} onClick={() => void release.run()}>
              Devolver a la IA
            </Button>
          )}
          {c.status !== "closed" && (
            <Button variant="secondary" className="px-3 py-1.5" loading={close.pending} onClick={() => void close.run()}>
              Marcar como resuelta
            </Button>
          )}
        </div>
        {error && <PlanLimitAlert error={error} />}
      </div>

      <ol className="max-h-[28rem] space-y-3 overflow-y-auto p-4" aria-live="polite">
        {c.messages.map((m) => (
          <Message key={m.id} message={m} />
        ))}
        <li ref={end as React.Ref<HTMLLIElement>} aria-hidden />
      </ol>

      {humanInbox && (
      <form
        className="space-y-2 border-t border-border p-4"
        onSubmit={(e) => {
          e.preventDefault();
          if (draft.trim()) void reply.run();
        }}
      >
        {reply.error?.field("content") && <Alert>{reply.error.field("content")}</Alert>}
        <textarea
          aria-label="Responder al cliente"
          rows={3}
          maxLength={2000}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && (e.metaKey || e.ctrlKey) && draft.trim()) {
              e.preventDefault();
              void reply.run();
            }
          }}
          placeholder={
            c.status === "human"
              ? "Escribe tu respuesta…"
              : "Escribe tu respuesta… Al enviarla tomarás la conversación y la IA dejará de contestar."
          }
          className="w-full resize-y rounded-lg border border-border bg-card px-3 py-2 text-sm placeholder:text-muted focus-visible:outline-2 focus-visible:outline-brand"
        />
        <div className="flex items-center justify-between gap-3">
          <span className="text-xs text-muted">Ctrl/⌘ + Enter para enviar</span>
          <Button type="submit" disabled={!draft.trim()} loading={reply.pending}>
            Enviar respuesta
          </Button>
        </div>
      </form>
      )}
    </Card>
  );
}

function Message({ message }: { message: ConversationMessage }) {
  const isUser = message.role === "user";
  const isHuman = message.role === "human";
  return (
    <li className={cn("flex flex-col", isUser ? "items-end" : "items-start")}>
      <p
        className={cn(
          "max-w-[85%] whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm",
          isUser && "bg-brand text-white dark:text-stone-950",
          message.role === "assistant" && "bg-brand-soft",
          isHuman && "bg-info-soft",
        )}
      >
        {message.content}
      </p>
      <p className="mt-1 text-xs text-muted">
        {isUser ? "Cliente" : isHuman ? `${message.sender_name ?? "Equipo"} (equipo)` : "Agente IA"} · {formatDateTime(message.created_at)}
      </p>
      {message.role === "assistant" && (message.sources.length > 0 || message.model) && (
        <details className="mt-1 max-w-[85%] text-xs text-muted">
          <summary className="cursor-pointer select-none">
            {message.sources.length > 0 ? `${message.sources.length} fuente${message.sources.length > 1 ? "s" : ""}` : "Sin fuentes"}
            {" · "}
            {message.input_tokens + message.output_tokens} tokens
          </summary>
          <ul className="mt-1 space-y-0.5">
            {message.sources.map((s, i) => (
              <li key={i}>
                {s.title} <span>· {Math.round(s.similarity * 100)}%</span>
              </li>
            ))}
            {message.model && <li>Modelo: {message.model}</li>}
          </ul>
        </details>
      )}
    </li>
  );
}
