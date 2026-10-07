<?php

namespace App\Services\Knowledge;

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Billing\UsageRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pipeline de un documento: extraer texto → trocear → embeddings → guardar vectores.
 * Reemplaza por completo los fragmentos previos, así sirve igual para el primer
 * procesamiento, para actualizar el contenido y para reprocesar.
 */
class DocumentProcessor
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly EmbeddingProvider $embeddings,
        private readonly UsageRecorder $usage,
    ) {}

    /** @throws DocumentProcessingException cuando el problema está en el documento (no se reintenta). */
    public function process(Document $document): void
    {
        $document->forceFill(['status' => DocumentStatus::Processing, 'error' => null])->save();

        if ($document->type === DocumentType::File) {
            $path = Storage::disk(Document::DISK)->path($document->file_path);
            $document->content = $this->extractor->extract($path, pathinfo($document->original_filename, PATHINFO_EXTENSION));
        }

        $chunks = TextChunker::fromConfig()->chunk($document->indexableText());

        if ($chunks === []) {
            throw new DocumentProcessingException('El documento no tiene contenido.');
        }
        if (count($chunks) > config('knowledge.max_chunks_per_document')) {
            throw new DocumentProcessingException('El documento es demasiado largo. Divídelo en partes más pequeñas.');
        }

        $vectors = [];
        $model = null;
        $tokens = 0;
        foreach (array_chunk($chunks, (int) config('knowledge.embedding_batch_size')) as $batch) {
            $response = $this->embeddings->embed($batch);
            $model = $response->model;
            $tokens += $response->tokens;
            array_push($vectors, ...$response->vectors);
        }

        $this->usage->embedding($model, $tokens);

        DB::transaction(function () use ($document, $chunks, $vectors, $model) {
            $document->chunks()->delete();

            foreach ($chunks as $position => $content) {
                DocumentChunk::create([
                    'organization_id' => $document->organization_id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                    'document_id' => $document->id,
                    'position' => $position,
                    'content' => $content,
                    'token_count' => (int) ceil(mb_strlen($content) / 4),
                    'embedding_model' => $model,
                    'embedding' => Vector::toSql($vectors[$position]),
                ]);
            }

            $document->forceFill([
                'status' => DocumentStatus::Ready,
                'error' => null,
                'chunk_count' => count($chunks),
                'processed_at' => now(),
            ])->save();
        });
    }
}
