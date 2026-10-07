<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeSearchRequest;
use App\Models\KnowledgeBase;
use App\Services\Knowledge\KnowledgeSearch;

/** KB-007: búsqueda vectorial. En el panel sirve para comprobar qué encontraría el agente. */
class KnowledgeSearchController extends Controller
{
    public function store(KnowledgeSearchRequest $request, KnowledgeBase $knowledgeBase, KnowledgeSearch $search)
    {
        $results = $search->search($knowledgeBase, $request->validated('query'));

        return response()->json($results->map(fn ($chunk) => [
            'document_id' => $chunk->document_id,
            'title' => $chunk->document->title,
            'content' => $chunk->content,
            'similarity' => round($chunk->similarity, 4),
        ])->values());
    }
}
