<?php

namespace App\Services\Knowledge;

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KnowledgeSearch
{
    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * Fragmentos más parecidos a la consulta dentro de una base de conocimiento.
     * Cada resultado trae `similarity` (coseno, 0–1) y la relación `document`.
     *
     * @return Collection<int, DocumentChunk>
     */
    public function search(KnowledgeBase $knowledgeBase, string $query, ?int $limit = null): Collection
    {
        $limit ??= (int) config('knowledge.search_limit');
        $minSimilarity = (float) config('knowledge.min_similarity');

        $vector = Vector::toSql($this->embeddings->embed([$query])->vectors[0]);

        // El filtro por base de conocimiento se aplica después del índice HNSW;
        // el escaneo iterativo evita quedarse con menos resultados de los pedidos.
        $rows = DB::transaction(function () use ($knowledgeBase, $vector, $limit) {
            DB::statement("SET LOCAL hnsw.iterative_scan = 'strict_order'");

            return DocumentChunk::query()
                ->select(['document_chunks.id', 'document_chunks.knowledge_base_id', 'document_chunks.document_id', 'document_chunks.position', 'document_chunks.content'])
                ->selectRaw('1 - (document_chunks.embedding <=> ?::vector) as similarity', [$vector])
                ->where('knowledge_base_id', $knowledgeBase->id)
                // Vectores de otro modelo no son comparables: se ignoran hasta reindexar (knowledge:reindex).
                ->where('embedding_model', $this->embeddings->model())
                ->whereHas('document', fn ($q) => $q->where('status', DocumentStatus::Ready->value))
                ->orderByRaw('document_chunks.embedding <=> ?::vector', [$vector])
                ->limit($limit)
                ->with('document:id,title,type')
                ->get();
        });

        return $rows->filter(fn (DocumentChunk $chunk) => $chunk->similarity >= $minSimilarity)->values();
    }
}
