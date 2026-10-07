<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(KnowledgeBase $knowledgeBase): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        return DocumentResource::collection($knowledgeBase->documents()->latest('id')->get());
    }

    public function store(StoreDocumentRequest $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $type = DocumentType::from($request->validated('type'));

        $document = new Document;
        $document->forceFill(['knowledge_base_id' => $knowledgeBase->id, 'type' => $type, 'status' => DocumentStatus::Pending]);

        if ($type === DocumentType::File) {
            $file = $request->file('file');
            $name = basename($file->getClientOriginalName());

            $document->forceFill([
                'title' => $request->validated('title') ?: pathinfo($name, PATHINFO_FILENAME),
                'original_filename' => $name,
                'size_bytes' => $file->getSize(),
                'file_path' => $file->store("organizations/{$this->current->id()}/documents", Document::DISK),
            ]);
        } else {
            $document->fill($request->safe()->only(['title', 'content']));
        }

        $document->save();
        ProcessDocument::dispatch($document->id);

        return (new DocumentResource($document))->response()->setStatusCode(202);
    }

    public function show(Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return (new DocumentResource($document))->withContent();
    }

    /** KB-009: al cambiar lo que se indexa, el documento se vuelve a procesar. */
    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        $document->fill($request->validated())->save();

        $indexedTextChanged = $document->wasChanged('content')
            || ($document->type === DocumentType::Faq && $document->wasChanged('title'));

        if ($indexedTextChanged) {
            $this->queueReprocessing($document);
        }

        return (new DocumentResource($document))->withContent();
    }

    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);
        $document->delete();

        return response()->noContent();
    }

    public function reprocess(Document $document): JsonResponse
    {
        $this->authorize('update', $document);
        $this->queueReprocessing($document);

        return (new DocumentResource($document))->response()->setStatusCode(202);
    }

    private function queueReprocessing(Document $document): void
    {
        $document->forceFill(['status' => DocumentStatus::Pending, 'error' => null])->save();
        ProcessDocument::dispatch($document->id);
    }
}
