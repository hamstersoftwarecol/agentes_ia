"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Alert, Button, Card, Input, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import type { KnowledgeBase } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

export function KnowledgeListView() {
  const canManage = useCanManage();
  const router = useRouter();
  const bases = useApi<KnowledgeBase[]>("/knowledge-bases");
  const [name, setName] = useState("");

  const create = useAction(async () => {
    const created = await api<KnowledgeBase>("/knowledge-bases", { method: "POST", body: { name: name.trim() } });
    router.push(`/knowledge/${created.id}`);
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Conocimiento</h1>
        <p className="mt-1 text-sm text-muted">
          La información de tu negocio que usan los agentes para responder: servicios, precios, horarios, políticas…
        </p>
      </div>

      {canManage && (
        <Card>
          <h2 className="font-semibold">Nueva base de conocimiento</h2>
          <form
            className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
            onSubmit={(e) => {
              e.preventDefault();
              void create.run();
            }}
          >
            <div className="flex-1">
              <Input
                label="Nombre"
                required
                maxLength={120}
                placeholder="Servicios y precios"
                value={name}
                onChange={(e) => setName(e.target.value)}
                error={create.error?.field("name")}
              />
            </div>
            <Button type="submit" loading={create.pending}>
              Crear
            </Button>
          </form>
          {create.error && !create.error.field("name") && (
            <div className="mt-3">
              <Alert>{create.error.message}</Alert>
            </div>
          )}
        </Card>
      )}

      {bases.loading && <Spinner className="size-5" />}
      {bases.error && <Alert>{bases.error.message}</Alert>}
      {bases.data?.length === 0 && (
        <p className="text-sm text-muted">Todavía no hay bases de conocimiento. Crea la primera para empezar.</p>
      )}

      <ul className="grid gap-4 sm:grid-cols-2">
        {bases.data?.map((kb) => (
          <li key={kb.id}>
            <Link href={`/knowledge/${kb.id}`} className="block h-full">
              <Card className="h-full p-5 transition-colors hover:bg-brand-soft">
                <h2 className="font-semibold">{kb.name}</h2>
                {kb.description && <p className="mt-1 line-clamp-2 text-sm text-muted">{kb.description}</p>}
                <p className="mt-3 text-sm text-muted">
                  {kb.documents_count} documento{kb.documents_count === 1 ? "" : "s"} · {kb.ready_documents_count} listo
                  {kb.ready_documents_count === 1 ? "" : "s"}
                </p>
              </Card>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
