"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { PlanLimitAlert, useBilling } from "@/components/BillingProvider";
import { Alert, Button, Card, cn, Textarea } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import type { Agent } from "@/lib/types";
import { useAction } from "@/lib/use-action";

/** Instalación del widget en un sitio web + apariencia (posición y color). */
export function WidgetCard({ agent, onSaved }: { agent: Agent; onSaved: () => void }) {
  const canManage = useCanManage();
  const { billing } = useBilling();
  const canCustomize = billing?.plan?.features.widget_customization ?? false;
  const maxSites = billing?.plan?.max_sites ?? null;
  const [domains, setDomains] = useState(agent.allowed_domains.join("\n"));
  const [position, setPosition] = useState(agent.widget_position);
  const [color, setColor] = useState(agent.widget_color);
  const [previewing, setPreviewing] = useState(false);
  const [copied, setCopied] = useState(false);

  // En producción lo recomendable es que el widget hable directo con la API (NEXT_PUBLIC_WIDGET_API_URL):
  // así Laravel ve la IP real del visitante en vez de la del servidor Next.
  const apiUrl = process.env.NEXT_PUBLIC_WIDGET_API_URL;
  const snippet =
    `<script src="${window.location.origin}/widget.js" data-agent="${agent.public_id}"` +
    (apiUrl ? ` data-api="${apiUrl}"` : "") +
    ` async></script>`;

  const save = useAction(async () => {
    // Si se estaba probando, se vuelve a cargar para que refleje la apariencia nueva.
    setPreviewing(false);
    await api(`/agents/${agent.id}`, { method: "PATCH", body: { widget_position: position, widget_color: color } });
    onSaved();
  });

  const saveDomains = useAction(async () => {
    const list = domains.split(/[\n,]/).map((d) => d.trim()).filter(Boolean);
    const updated = await api<{ allowed_domains: string[] }>(`/agents/${agent.id}`, { method: "PATCH", body: { allowed_domains: list } });
    setDomains(updated.allowed_domains.join("\n")); // el servidor los normaliza (sin https://, sin www.)
    onSaved();
  });

  // Prueba en vivo: inyecta el widget real en esta misma página y lo quita al salir.
  useEffect(() => {
    if (!previewing) return;
    const tag = document.createElement("script");
    tag.src = "/widget.js";
    tag.async = true;
    tag.setAttribute("data-agent", agent.public_id);
    tag.setAttribute("data-preview", ""); // sin caché de configuración, para ver los cambios al instante
    document.body.appendChild(tag);

    return () => {
      tag.remove();
      (window as unknown as { HamsterWidget?: { destroy: () => void } }).HamsterWidget?.destroy();
    };
  }, [previewing, agent.public_id]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(snippet);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* el portapapeles puede estar bloqueado: el código sigue visible para copiarlo a mano */
    }
  }

  return (
    <Card>
      <h2 className="font-semibold">Instalar en tu sitio web</h2>
      <p className="mt-1 text-sm text-muted">
        Pega este código antes de <code>&lt;/body&gt;</code> en tu página. El chat aparece en una esquina.
      </p>
      {!agent.is_active && (
        <div className="mt-3">
          <Alert tone="info">Este agente está inactivo: el widget no se mostrará en tu sitio hasta que lo actives.</Alert>
        </div>
      )}

      <form
        className="mt-5 space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          void saveDomains.run();
        }}
      >
        <fieldset disabled={!canManage} className="space-y-3">
          {saveDomains.done && <Alert tone="success">Sitios guardados.</Alert>}
          {saveDomains.error && <PlanLimitAlert error={saveDomains.error} />}
          <Textarea
            label="Sitios donde se mostrará el chat"
            rows={3}
            placeholder={"mitienda.com"}
            value={domains}
            onChange={(e) => setDomains(e.target.value)}
            error={saveDomains.error?.field("allowed_domains") ?? saveDomains.error?.errors["allowed_domains.0"]?.[0]}
            hint={`Uno por línea. «mitienda.com» incluye www y demás subdominios. Si está vacío, el chat solo funciona en la vista previa de este panel.${maxSites ? ` Tu plan permite ${maxSites} sitio${maxSites === 1 ? "" : "s"} en total.` : ""}`}
          />
          <Button type="submit" variant="secondary" loading={saveDomains.pending}>
            Guardar sitios
          </Button>
        </fieldset>
      </form>

      <p className="mt-5 text-sm font-medium">Código para pegar</p>
      <pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-border bg-background p-3 font-mono text-xs">
        {snippet}
      </pre>
      <div className="mt-2">
        <Button variant="secondary" className="px-3 py-1.5" onClick={() => void copy()}>
          {copied ? "¡Copiado!" : "Copiar código"}
        </Button>
      </div>

      <hr className="my-5 border-border" />

      <h3 className="text-sm font-medium">Apariencia</h3>
      {!canCustomize && (
        <p className="mt-1 text-sm text-muted">
          Personalizar la posición y el color del widget está disponible desde el plan Business.{" "}
          <Link href="/billing" className="text-brand hover:underline">
            Ver planes
          </Link>
        </p>
      )}
      <form
        className="mt-3 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save.run();
        }}
      >
        <fieldset disabled={!canManage || !canCustomize} className="space-y-4">
          {save.error && <Alert>{save.error.field("widget_color") ?? save.error.field("widget_position") ?? save.error.message}</Alert>}
          {save.done && <Alert tone="success">Apariencia guardada.</Alert>}

          <div className="flex flex-wrap items-end gap-6">
            <div>
              <legend className="mb-1.5 text-sm font-medium">Posición</legend>
              <div className="flex gap-2">
                {(
                  [
                    ["left", "Izquierda"],
                    ["right", "Derecha"],
                  ] as const
                ).map(([value, label]) => (
                  <label
                    key={value}
                    className={cn(
                      "cursor-pointer rounded-lg border border-border px-3 py-1.5 text-sm",
                      position === value && "border-brand bg-brand-soft font-medium",
                    )}
                  >
                    <input
                      type="radio"
                      name="widget-position"
                      value={value}
                      checked={position === value}
                      onChange={() => setPosition(value)}
                      className="sr-only"
                    />
                    {label}
                  </label>
                ))}
              </div>
            </div>

            <label className="text-sm font-medium">
              <span className="mb-1.5 block">Color</span>
              <span className="flex items-center gap-2">
                <input
                  type="color"
                  value={color}
                  onChange={(e) => setColor(e.target.value)}
                  className="h-9 w-12 cursor-pointer rounded-lg border border-border bg-card p-1"
                />
                <code className="text-xs text-muted">{color}</code>
              </span>
            </label>
          </div>

          <Button type="submit" loading={save.pending}>
            Guardar apariencia
          </Button>
        </fieldset>
      </form>

      <hr className="my-5 border-border" />

      <h3 className="text-sm font-medium">Probar el widget</h3>
      <p className="mt-1 text-sm text-muted">
        Lo carga aquí mismo, tal como lo verán tus clientes. Las conversaciones de prueba quedan en el historial.
      </p>
      <Button variant={previewing ? "secondary" : "primary"} className="mt-3" onClick={() => setPreviewing((v) => !v)}>
        {previewing ? "Quitar de esta página" : "Probar en esta página"}
      </Button>
    </Card>
  );
}
