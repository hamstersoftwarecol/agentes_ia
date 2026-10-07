<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeBaseRequest;
use App\Http\Resources\KnowledgeBaseResource;
use App\Models\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KnowledgeBaseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', KnowledgeBase::class);

        return KnowledgeBaseResource::collection(
            KnowledgeBase::withCount(['documents', 'readyDocuments'])->orderBy('name')->orderBy('id')->get()
        );
    }

    public function store(KnowledgeBaseRequest $request): JsonResponse
    {
        $knowledgeBase = KnowledgeBase::create($request->validated());

        return (new KnowledgeBaseResource($knowledgeBase->loadCount(['documents', 'readyDocuments'])))
            ->response()->setStatusCode(201);
    }

    public function show(KnowledgeBase $knowledgeBase): KnowledgeBaseResource
    {
        $this->authorize('view', $knowledgeBase);

        return new KnowledgeBaseResource($knowledgeBase->loadCount(['documents', 'readyDocuments']));
    }

    public function update(KnowledgeBaseRequest $request, KnowledgeBase $knowledgeBase): KnowledgeBaseResource
    {
        $knowledgeBase->update($request->validated());

        return new KnowledgeBaseResource($knowledgeBase->loadCount(['documents', 'readyDocuments']));
    }

    public function destroy(KnowledgeBase $knowledgeBase)
    {
        $this->authorize('delete', $knowledgeBase);
        $knowledgeBase->delete();

        return response()->noContent();
    }
}
