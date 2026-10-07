"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { AddDocument } from "@/components/knowledge/AddDocument";
import { DocumentList } from "@/components/knowledge/DocumentList";
import { SearchTester } from "@/components/knowledge/SearchTester";
import { Alert, Button, Card, Input, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { useCanManage } from "@/lib/auth";
import type { KnowledgeBase, KnowledgeDocument } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

const isBusy = (docs: KnowledgeDocument[]) =>
  docs.some((d) => d.status === "pending" || d.status === "processing") ? 2000 : false;

export function KnowledgeDetailView() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const canManage = useCanManage();
  const kb = useApi<KnowledgeBase>(`/knowledge-bases/${id}`);
  // Mientras haya documentos procesándose se consulta cada 2 s.
  const docs = useApi<KnowledgeDocument[]>(`/knowledge-bases/${id}/documents`, isBusy);

  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState("");

  const rename = useAction(async () => {
    await api(`/knowledge-bases/${id}`, { method: "PATCH", body: { name: name.trim() } });
    await kb.reload();
    setRenaming(false);
  });
  const remove = useAction(async () => {
    await api(`/knowledge-bases/${id}`, { method: "DELETE" });
    router.replace("/knowledge");
  });

  if (kb.loading) return <Spinner className="size-5" />;
  if (kb.error || !kb.data) {
    return (
      <div className="space-y-4">
        <Alert>{kb.error?.status === 404 ? "No encontramos esta base de conocimiento." : (kb.error?.message ?? "Error")}</Alert>
        <Link href="/knowledge" className="text-sm text-brand hover:underline">
          ← Volver a Conocimiento
        </Link>
      </div>
    );
  }
  const base = kb.data;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/knowledge" className="text-sm text-muted hover:underline">
          ← Conocimiento
        </Link>
        {renaming ? (
          <form
            className="mt-2 flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              void rename.run();
            }}
          >
            <div className="w-72">
              <Input label="Nombre" required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} error={rename.error?.field("name")} />
            </div>
            <Button type="submit" loading={rename.pending}>
              Guardar
            </Button>
            <Button type="button" variant="ghost" onClick={() => setRenaming(false)}>
              Cancelar
            </Button>
          </form>
        ) : (
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold tracking-tight">{base.name}</h1>
            {canManage && (
              <Button
                variant="ghost"
                className="px-3 py-1.5"
                onClick={() => {
                  setName(base.name);
                  setRenaming(true);
                }}
              >
                Renombrar
              </Button>
            )}
          </div>
        )}
      </div>

      {canManage && <AddDocument knowledgeBaseId={base.id} onAdded={() => void docs.reload()} />}

      <DocumentList
        documents={docs.data}
        loading={docs.loading}
        error={docs.error?.message}
        canManage={canManage}
        onChanged={() => {
          void docs.reload();
          void kb.reload();
        }}
      />

      <SearchTester knowledgeBaseId={base.id} hasDocuments={(docs.data?.length ?? 0) > 0} />

      {canManage && (
        <Card>
          <h2 className="font-semibold">Eliminar base de conocimiento</h2>
          <p className="mt-1 text-sm text-muted">
            Se borran todos sus documentos y archivos. Los agentes que la usan quedarán sin conocimiento.
          </p>
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
              if (window.confirm(`¿Eliminar «${base.name}» y todos sus documentos? Esta acción no se puede deshacer.`)) void remove.run();
            }}
          >
            Eliminar base de conocimiento
          </Button>
        </Card>
      )}
    </div>
  );
}
