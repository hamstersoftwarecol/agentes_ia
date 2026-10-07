"use client";

import { useState } from "react";
import { Alert, Badge, Button, Card, Input, Spinner, Textarea } from "@/components/ui";
import { api } from "@/lib/api";
import type { DocumentStatus, DocumentType, KnowledgeDocument } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

const TYPE_LABEL: Record<DocumentType, string> = { text: "Texto", faq: "Pregunta", file: "Archivo" };

const STATUS: Record<DocumentStatus, { label: string; tone: "neutral" | "info" | "success" | "danger" }> = {
  pending: { label: "En cola", tone: "neutral" },
  processing: { label: "Procesando…", tone: "info" },
  ready: { label: "Listo", tone: "success" },
  failed: { label: "Error", tone: "danger" },
};

function formatSize(bytes: number | null) {
  if (bytes === null) return "";
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

type Props = {
  documents: KnowledgeDocument[] | undefined;
  loading: boolean;
  error?: string;
  canManage: boolean;
  onChanged: () => void;
};

export function DocumentList({ documents, loading, error, canManage, onChanged }: Props) {
  return (
    <Card>
      <h2 className="font-semibold">Documentos</h2>
      {loading && (
        <div className="mt-4">
          <Spinner className="size-5" />
        </div>
      )}
      {error && (
        <div className="mt-4">
          <Alert>{error}</Alert>
        </div>
      )}
      {documents?.length === 0 && <p className="mt-3 text-sm text-muted">Aún no hay documentos. Agrega el primero arriba.</p>}
      <ul className="mt-2 divide-y divide-border">
        {documents?.map((doc) => (
          <DocumentRow key={doc.id} doc={doc} canManage={canManage} onChanged={onChanged} />
        ))}
      </ul>
    </Card>
  );
}

function DocumentRow({ doc, canManage, onChanged }: { doc: KnowledgeDocument; canManage: boolean; onChanged: () => void }) {
  const [editing, setEditing] = useState(false);
  const status = STATUS[doc.status];

  const remove = useAction(async () => {
    await api(`/documents/${doc.id}`, { method: "DELETE" });
    onChanged();
  });
  const reprocess = useAction(async () => {
    await api(`/documents/${doc.id}/reprocess`, { method: "POST" });
    onChanged();
  });

  return (
    <li className="py-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate font-medium">{doc.title}</p>
          <p className="mt-0.5 text-xs text-muted">
            {TYPE_LABEL[doc.type]}
            {doc.original_filename && ` · ${doc.original_filename}`}
            {doc.size_bytes !== null && ` · ${formatSize(doc.size_bytes)}`}
            {doc.status === "ready" && ` · ${doc.chunk_count} fragmento${doc.chunk_count === 1 ? "" : "s"}`}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={status.tone}>{status.label}</Badge>
          {canManage && (
            <>
              {doc.type !== "file" && (
                <Button variant="ghost" className="px-3 py-1.5" onClick={() => setEditing((v) => !v)}>
                  {editing ? "Cerrar" : "Editar"}
                </Button>
              )}
              {doc.status === "failed" && (
                <Button variant="secondary" className="px-3 py-1.5" loading={reprocess.pending} onClick={() => void reprocess.run()}>
                  Reintentar
                </Button>
              )}
              <Button
                variant="danger"
                className="px-3 py-1.5"
                loading={remove.pending}
                onClick={() => {
                  if (window.confirm(`¿Eliminar «${doc.title}»?`)) void remove.run();
                }}
              >
                Eliminar
              </Button>
            </>
          )}
        </div>
      </div>
      {doc.status === "failed" && doc.error && (
        <div className="mt-3">
          <Alert>{doc.error}</Alert>
        </div>
      )}
      {(remove.error || reprocess.error) && (
        <div className="mt-3">
          <Alert>{(remove.error ?? reprocess.error)!.message}</Alert>
        </div>
      )}
      {editing && (
        <EditDocument
          doc={doc}
          onSaved={() => {
            setEditing(false);
            onChanged();
          }}
        />
      )}
    </li>
  );
}

function EditDocument({ doc, onSaved }: { doc: KnowledgeDocument; onSaved: () => void }) {
  // El listado no trae el contenido: se pide al abrir el editor.
  const full = useApi<KnowledgeDocument>(`/documents/${doc.id}`);

  if (full.error) return <div className="mt-3"><Alert>{full.error.message}</Alert></div>;
  if (!full.data) return <div className="mt-3"><Spinner className="size-5" /></div>;

  return <EditForm doc={full.data} onSaved={onSaved} />;
}

function EditForm({ doc, onSaved }: { doc: KnowledgeDocument; onSaved: () => void }) {
  const [form, setForm] = useState({ title: doc.title, content: doc.content ?? "" });
  const isFaq = doc.type === "faq";

  const save = useAction(async () => {
    await api(`/documents/${doc.id}`, { method: "PUT", body: { title: form.title.trim(), content: form.content } });
    onSaved();
  });

  return (
    <form
      className="mt-4 space-y-4 rounded-lg border border-border p-4"
      onSubmit={(e) => {
        e.preventDefault();
        void save.run();
      }}
    >
      {save.error && !Object.keys(save.error.errors).length && <Alert>{save.error.message}</Alert>}
      <Input label={isFaq ? "Pregunta" : "Título"} required maxLength={200} value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} error={save.error?.field("title")} />
      <Textarea
        label={isFaq ? "Respuesta" : "Contenido"}
        required
        rows={isFaq ? 4 : 8}
        value={form.content}
        onChange={(e) => setForm((f) => ({ ...f, content: e.target.value }))}
        error={save.error?.field("content")}
        hint="Al guardar, el documento se vuelve a procesar."
      />
      <Button type="submit" loading={save.pending}>
        Guardar cambios
      </Button>
    </form>
  );
}
