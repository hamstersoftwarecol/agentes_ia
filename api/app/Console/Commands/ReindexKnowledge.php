<?php

namespace App\Console\Commands;

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexKnowledge extends Command
{
    protected $signature = 'knowledge:reindex
                            {--stale : Solo documentos con fragmentos de un modelo de embeddings distinto al actual}
                            {--organization= : Limitar a una empresa (id)}';

    protected $description = 'Vuelve a generar los vectores de los documentos (p. ej. tras cambiar de proveedor de embeddings)';

    public function handle(EmbeddingProvider $embeddings): int
    {
        $model = $embeddings->model();

        // Es una tarea de mantenimiento global: no hay empresa actual, se salta el scope a propósito.
        $query = Document::withoutGlobalScopes()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($this->option('stale'), fn ($q) => $q->whereExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('document_chunks')
                    ->whereColumn('document_chunks.document_id', 'documents.id')
                    ->where('document_chunks.embedding_model', '!=', $model)
            ));

        $count = 0;
        $query->each(function (Document $document) use (&$count) {
            $document->forceFill(['status' => DocumentStatus::Pending, 'error' => null])->save();
            ProcessDocument::dispatch($document->id);
            $count++;
        });

        $this->info("{$count} documento(s) en cola para reindexar con el modelo «{$model}».");

        return self::SUCCESS;
    }
}
