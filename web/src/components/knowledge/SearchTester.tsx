"use client";

import { useState } from "react";
import { Alert, Button, Card, Input } from "@/components/ui";
import { api } from "@/lib/api";
import type { SearchResult } from "@/lib/types";
import { useAction } from "@/lib/use-action";

/** KB-007: muestra qué fragmentos encontraría el agente para una pregunta. */
export function SearchTester({ knowledgeBaseId, hasDocuments }: { knowledgeBaseId: number; hasDocuments: boolean }) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[] | null>(null);

  const search = useAction(async () => {
    setResults(await api<SearchResult[]>(`/knowledge-bases/${knowledgeBaseId}/search`, { method: "POST", body: { query: query.trim() } }));
  });

  return (
    <Card>
      <h2 className="font-semibold">Probar la búsqueda</h2>
      <p className="mt-1 text-sm text-muted">Escribe una pregunta de cliente y mira qué información encontraría el agente.</p>
      <form
        className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
        onSubmit={(e) => {
          e.preventDefault();
          void search.run();
        }}
      >
        <div className="flex-1">
          <Input label="Pregunta" required maxLength={500} placeholder="¿Cuánto cuesta un corte?" value={query} onChange={(e) => setQuery(e.target.value)} />
        </div>
        <Button type="submit" variant="secondary" disabled={!hasDocuments} loading={search.pending}>
          Buscar
        </Button>
      </form>
      {!hasDocuments && <p className="mt-2 text-xs text-muted">Agrega al menos un documento para poder buscar.</p>}
      {search.error && (
        <div className="mt-3">
          <Alert>{search.error.message}</Alert>
        </div>
      )}
      {results?.length === 0 && <p className="mt-4 text-sm text-muted">No se encontró información relacionada.</p>}
      <ol className="mt-4 space-y-3">
        {results?.map((r, i) => (
          <li key={i} className="rounded-lg border border-border p-3 text-sm">
            <p className="font-medium">
              {r.title} <span className="font-normal text-muted">· similitud {Math.round(r.similarity * 100)}%</span>
            </p>
            <p className="mt-1 whitespace-pre-wrap text-muted">{r.content}</p>
          </li>
        ))}
      </ol>
    </Card>
  );
}
