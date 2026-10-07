"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { PlanLimitAlert } from "@/components/BillingProvider";
import { Alert, Button, Input, Select, Textarea } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import { LANGUAGES, TONES, type Agent, type KnowledgeBase } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

type Props = {
  /** Sin `agent` el formulario crea uno nuevo. */
  agent?: Agent;
  onSaved?: (agent: Agent) => void;
};

export function AgentForm({ agent, onSaved }: Props) {
  const router = useRouter();
  const canManage = useCanManage();
  const knowledge = useApi<KnowledgeBase[]>("/knowledge-bases");

  const [form, setForm] = useState({
    name: agent?.name ?? "",
    role: agent?.role ?? "",
    goal: agent?.goal ?? "",
    tone: agent?.tone ?? "friendly_professional",
    language: agent?.language ?? "es",
    welcome_message: agent?.welcome_message ?? "",
    instructions: agent?.instructions ?? "",
    knowledge_base_id: agent?.knowledge_base_id ? String(agent.knowledge_base_id) : "",
    is_active: agent?.is_active ?? true,
  });
  const set =
    (key: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm((f) => ({ ...f, [key]: e.target.value }));

  const save = useAction(async () => {
    const empty = (v: string) => (v.trim() === "" ? null : v.trim());
    const body = {
      name: form.name.trim(),
      role: empty(form.role),
      goal: empty(form.goal),
      tone: form.tone,
      language: form.language,
      welcome_message: empty(form.welcome_message),
      instructions: empty(form.instructions),
      knowledge_base_id: form.knowledge_base_id ? Number(form.knowledge_base_id) : null,
      is_active: form.is_active,
    };

    if (agent) {
      const updated = await api<Agent>(`/agents/${agent.id}`, { method: "PATCH", body });
      onSaved?.(updated);
    } else {
      const created = await api<Agent>("/agents", { method: "POST", body });
      router.replace(`/agents/${created.id}`);
    }
  });
  const err = (name: string) => save.error?.field(name);
  const noKnowledge = knowledge.data?.length === 0;

  return (
    <form
      className="space-y-5"
      onSubmit={(e) => {
        e.preventDefault();
        void save.run();
      }}
    >
      <fieldset disabled={!canManage} className="space-y-5">
        {save.error && !Object.keys(save.error.errors).length && <PlanLimitAlert error={save.error} />}
        {save.done && agent && <Alert tone="success">Cambios guardados.</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            label="Nombre del agente"
            required
            maxLength={80}
            placeholder="Sofía"
            value={form.name}
            onChange={set("name")}
            error={err("name")}
          />
          <Input
            label="Rol"
            maxLength={120}
            placeholder="Asesora comercial"
            value={form.role}
            onChange={set("role")}
            error={err("role")}
          />
        </div>

        <Textarea
          label="Objetivo"
          maxLength={1000}
          placeholder="Ayudar a clientes interesados en nuestros servicios y conseguir reservas."
          value={form.goal}
          onChange={set("goal")}
          error={err("goal")}
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Tono" value={form.tone} onChange={set("tone")} error={err("tone")}>
            {TONES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </Select>
          <Select label="Idioma" value={form.language} onChange={set("language")} error={err("language")}>
            {LANGUAGES.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </Select>
        </div>

        <Textarea
          label="Mensaje de bienvenida"
          maxLength={500}
          placeholder="¡Hola! Soy Sofía 👋 ¿En qué te puedo ayudar?"
          value={form.welcome_message}
          onChange={set("welcome_message")}
          error={err("welcome_message")}
        />

        <Textarea
          label="Reglas e instrucciones"
          rows={6}
          maxLength={5000}
          placeholder={"- No inventar precios.\n- Solicitar nombre y teléfono cuando haya intención de compra.\n- Transferir a una persona cuando no puedas responder."}
          hint="Opcional. El agente ya sabe que debe usar solo la información de tu empresa y no inventar datos."
          value={form.instructions}
          onChange={set("instructions")}
          error={err("instructions")}
        />

        <div className="space-y-1.5">
          <Select
            label="Base de conocimiento"
            value={form.knowledge_base_id}
            onChange={set("knowledge_base_id")}
            error={err("knowledge_base_id")}
          >
            <option value="">Sin base de conocimiento</option>
            {knowledge.data?.map((kb) => (
              <option key={kb.id} value={kb.id}>
                {kb.name}
              </option>
            ))}
          </Select>
          {noKnowledge && (
            <p className="text-xs text-muted">
              Aún no tienes ninguna.{" "}
              <Link href="/knowledge" className="text-brand hover:underline">
                Crea una base de conocimiento
              </Link>{" "}
              y vuelve para asignarla.
            </p>
          )}
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
            className="size-4 accent-[var(--brand)]"
          />
          Agente activo
        </label>

        <Button type="submit" loading={save.pending}>
          {agent ? "Guardar cambios" : "Crear agente"}
        </Button>
      </fieldset>
    </form>
  );
}
