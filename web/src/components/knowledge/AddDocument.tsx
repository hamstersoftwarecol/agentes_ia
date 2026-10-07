"use client";

import { useRef, useState } from "react";
import { Alert, Button, Card, cn, Input, Textarea } from "@/components/ui";
import { api } from "@/lib/api";
import { useAction } from "@/lib/use-action";

type Tab = "text" | "faq" | "file";

const TABS: { value: Tab; label: string }[] = [
  { value: "text", label: "Texto" },
  { value: "faq", label: "Pregunta frecuente" },
  { value: "file", label: "Archivo" },
];

const MAX_FILE_MB = 10;

export function AddDocument({ knowledgeBaseId, onAdded }: { knowledgeBaseId: number; onAdded: () => void }) {
  const [tab, setTab] = useState<Tab>("text");

  return (
    <Card>
      <h2 className="font-semibold">Agregar conocimiento</h2>
      <div role="tablist" className="mt-4 flex gap-1 border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.value}
            type="button"
            role="tab"
            aria-selected={tab === t.value}
            onClick={() => setTab(t.value)}
            className={cn(
              "-mb-px border-b-2 px-3 py-2 text-sm",
              tab === t.value ? "border-brand font-medium" : "border-transparent text-muted hover:text-foreground",
            )}
          >
            {t.label}
          </button>
        ))}
      </div>
      <div className="mt-5">
        {tab === "text" && <TextForm knowledgeBaseId={knowledgeBaseId} onAdded={onAdded} />}
        {tab === "faq" && <FaqForm knowledgeBaseId={knowledgeBaseId} onAdded={onAdded} />}
        {tab === "file" && <FileForm knowledgeBaseId={knowledgeBaseId} onAdded={onAdded} />}
      </div>
    </Card>
  );
}

type FormProps = { knowledgeBaseId: number; onAdded: () => void };

function TextForm({ knowledgeBaseId, onAdded }: FormProps) {
  const [title, setTitle] = useState("");
  const [content, setContent] = useState("");
  const add = useAction(async () => {
    await api(`/knowledge-bases/${knowledgeBaseId}/documents`, { method: "POST", body: { type: "text", title: title.trim(), content } });
    setTitle("");
    setContent("");
    onAdded();
  });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void add.run();
      }}
    >
      {add.done && <Alert tone="success">Agregado. Se está procesando.</Alert>}
      {add.error && !Object.keys(add.error.errors).length && <Alert>{add.error.message}</Alert>}
      <Input label="Título" required maxLength={200} placeholder="Servicios y precios" value={title} onChange={(e) => setTitle(e.target.value)} error={add.error?.field("title")} />
      <Textarea
        label="Contenido"
        required
        rows={7}
        placeholder="Pega aquí la información: servicios, precios, horarios, políticas…"
        value={content}
        onChange={(e) => setContent(e.target.value)}
        error={add.error?.field("content")}
      />
      <Button type="submit" loading={add.pending}>
        Agregar texto
      </Button>
    </form>
  );
}

function FaqForm({ knowledgeBaseId, onAdded }: FormProps) {
  const [question, setQuestion] = useState("");
  const [answer, setAnswer] = useState("");
  const add = useAction(async () => {
    await api(`/knowledge-bases/${knowledgeBaseId}/documents`, {
      method: "POST",
      body: { type: "faq", title: question.trim(), content: answer },
    });
    setQuestion("");
    setAnswer("");
    onAdded();
  });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void add.run();
      }}
    >
      {add.done && <Alert tone="success">Agregada. Se está procesando.</Alert>}
      {add.error && !Object.keys(add.error.errors).length && <Alert>{add.error.message}</Alert>}
      <Input label="Pregunta" required maxLength={200} placeholder="¿Aceptan pagos con tarjeta?" value={question} onChange={(e) => setQuestion(e.target.value)} error={add.error?.field("title")} />
      <Textarea label="Respuesta" required rows={4} placeholder="Sí, aceptamos todas las tarjetas." value={answer} onChange={(e) => setAnswer(e.target.value)} error={add.error?.field("content")} />
      <Button type="submit" loading={add.pending}>
        Agregar pregunta
      </Button>
    </form>
  );
}

function FileForm({ knowledgeBaseId, onAdded }: FormProps) {
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [tooBig, setTooBig] = useState(false);

  const add = useAction(async () => {
    if (!file) return;
    const body = new FormData();
    body.append("type", "file");
    body.append("file", file);
    await api(`/knowledge-bases/${knowledgeBaseId}/documents`, { method: "POST", body });
    setFile(null);
    if (input.current) input.current.value = "";
    onAdded();
  });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void add.run();
      }}
    >
      {add.done && <Alert tone="success">Archivo subido. Se está procesando.</Alert>}
      {(add.error || tooBig) && (
        <Alert>{tooBig ? `El archivo no puede pesar más de ${MAX_FILE_MB} MB.` : (add.error?.field("file") ?? add.error?.message)}</Alert>
      )}
      <div className="space-y-1.5">
        <label htmlFor="document-file" className="block text-sm font-medium">
          Archivo
        </label>
        <input
          ref={input}
          id="document-file"
          type="file"
          accept=".pdf,.txt,.md,application/pdf,text/plain,text/markdown"
          onChange={(e) => {
            const picked = e.target.files?.[0] ?? null;
            setTooBig(!!picked && picked.size > MAX_FILE_MB * 1024 * 1024);
            setFile(picked && picked.size <= MAX_FILE_MB * 1024 * 1024 ? picked : null);
          }}
          className="block w-full text-sm file:mr-3 file:rounded-lg file:border file:border-border file:bg-card file:px-3 file:py-2 file:text-sm hover:file:bg-brand-soft"
        />
        <p className="text-xs text-muted">PDF, TXT o MD, hasta {MAX_FILE_MB} MB. Los PDF escaneados (solo imágenes) no tienen texto que leer.</p>
      </div>
      <Button type="submit" disabled={!file} loading={add.pending}>
        Subir archivo
      </Button>
    </form>
  );
}
