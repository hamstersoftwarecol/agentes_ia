"use client";

import { useEffect, useRef, useState } from "react";
import { Alert, Button, Card, cn } from "@/components/ui";
import { api } from "@/lib/api";
import type { Agent, ChatMessage, TestChatResponse } from "@/lib/types";
import { useAction } from "@/lib/use-action";

type Turn = ChatMessage & { details?: TestChatResponse };

/** AI-008: conversación de prueba con el agente guardado. No se persiste. */
export function AgentTestChat({ agent }: { agent: Agent }) {
  const welcome = agent.welcome_message?.trim();
  const [turns, setTurns] = useState<Turn[]>([]);
  const [draft, setDraft] = useState("");
  const bottom = useRef<HTMLDivElement>(null);

  const send = useAction(async (text: string) => {
    const history: Turn[] = [...turns, { role: "user", content: text }];
    setTurns(history);
    const res = await api<TestChatResponse>(`/agents/${agent.id}/test-chat`, {
      method: "POST",
      // La bienvenida es solo de pantalla: no se envía al modelo.
      body: { messages: history.map(({ role, content }) => ({ role, content })) },
    });
    setTurns([...history, { role: "assistant", content: res.reply, details: res }]);
  });

  useEffect(() => {
    bottom.current?.scrollIntoView({ block: "end" });
  }, [turns, send.pending]);

  function submit() {
    const text = draft.trim();
    if (!text || send.pending) return;
    setDraft("");
    void send.run(text);
  }

  return (
    <Card className="flex h-[36rem] flex-col p-0">
      <div className="flex items-center justify-between border-b border-border px-4 py-3">
        <div>
          <h2 className="font-semibold">Probar a {agent.name}</h2>
          <p className="text-xs text-muted">Usa la configuración guardada. No se registra como conversación.</p>
        </div>
        <Button variant="ghost" className="px-3 py-1.5" onClick={() => setTurns([])} disabled={turns.length === 0}>
          Reiniciar
        </Button>
      </div>

      <div className="flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite">
        {welcome && <Bubble role="assistant">{welcome}</Bubble>}
        {turns.length === 0 && !welcome && (
          <p className="text-sm text-muted">Escribe un mensaje como si fueras un cliente.</p>
        )}
        {turns.map((turn, i) => (
          <div key={i} className="space-y-1.5">
            <Bubble role={turn.role}>{turn.content}</Bubble>
            {turn.details && <Details details={turn.details} />}
          </div>
        ))}
        {send.pending && <Bubble role="assistant">Escribiendo…</Bubble>}
        {send.error && <Alert>{send.error.field("messages") ?? send.error.message}</Alert>}
        <div ref={bottom} />
      </div>

      <form
        className="flex gap-2 border-t border-border p-3"
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
      >
        <input
          aria-label="Mensaje para el agente"
          className="min-w-0 flex-1 rounded-lg border border-border bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-brand"
          placeholder="Escribe un mensaje…"
          maxLength={2000}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
        />
        <Button type="submit" disabled={!draft.trim()} loading={send.pending}>
          Enviar
        </Button>
      </form>
    </Card>
  );
}

function Bubble({ role, children }: { role: "user" | "assistant"; children: React.ReactNode }) {
  return (
    <div className={cn("flex", role === "user" && "justify-end")}>
      <p
        className={cn(
          "max-w-[85%] whitespace-pre-wrap rounded-2xl px-3.5 py-2 text-sm",
          role === "user" ? "bg-brand text-white dark:text-stone-950" : "bg-brand-soft",
        )}
      >
        {children}
      </p>
    </div>
  );
}

function Details({ details }: { details: TestChatResponse }) {
  return (
    <details className="max-w-[85%] text-xs text-muted">
      <summary className="cursor-pointer select-none">
        {details.sources.length
          ? `${details.sources.length} fuente${details.sources.length > 1 ? "s" : ""} usada${details.sources.length > 1 ? "s" : ""}`
          : "Sin fuentes"}{" "}
        · {details.usage.input_tokens + details.usage.output_tokens} tokens
      </summary>
      <div className="mt-2 space-y-2">
        {details.action === "request_human" && (
          <p className="rounded-lg border border-border p-2 text-foreground">
            🔔 El agente pasaría esta conversación a una persona (en una conversación real quedaría «Esperando»).
          </p>
        )}
        {details.lead && (
          <p className="rounded-lg border border-border p-2 text-foreground">
            📇 Lead detectado: {[details.lead.name, details.lead.phone, details.lead.email].filter(Boolean).join(" · ")}
            {details.lead.interest ? ` — ${details.lead.interest}` : ""} (en la prueba no se guarda).
          </p>
        )}
        {details.sources.map((s, i) => (
          <div key={i} className="rounded-lg border border-border p-2">
            <p className="font-medium text-foreground">
              {s.title} <span className="font-normal text-muted">· similitud {Math.round(s.similarity * 100)}%</span>
            </p>
            <p className="mt-1">{s.excerpt}</p>
          </div>
        ))}
        <details>
          <summary className="cursor-pointer select-none">Ver prompt completo</summary>
          <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded-lg border border-border p-2 font-mono">
            {details.system_prompt}
          </pre>
        </details>
      </div>
    </details>
  );
}
