<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Knowledge\DocumentProcessingException;
use App\Services\Knowledge\DocumentProcessor;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    /** Fallos transitorios (red, límites del proveedor de IA) se reintentan. */
    public int $tries = 3;

    public function __construct(public int $documentId)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(DocumentProcessor $processor, CurrentOrganization $current): void
    {
        // En un job no hay usuario ni petición: el tenant sale del propio documento.
        $document = Document::withoutGlobalScopes()->find($this->documentId);
        if (! $document) {
            return; // se borró antes de procesarse
        }
        $current->set($document->organization);

        try {
            $processor->process($document);
        } catch (DocumentProcessingException $e) {
            $this->markFailed($document, $e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);
        if ($document) {
            $this->markFailed($document, 'No pudimos procesar el documento. Inténtalo de nuevo en unos minutos.');
        }
        report($e);
    }

    private function markFailed(Document $document, string $message): void
    {
        $document->forceFill(['status' => DocumentStatus::Failed, 'error' => $message])->save();
    }
}
