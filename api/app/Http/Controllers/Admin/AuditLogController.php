<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArrayResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['action' => ['nullable', 'string', 'max:64'], 'organization_id' => ['nullable', 'integer']]);

        $page = AuditLog::with(['user:id,name', 'organization:id,name'])
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->latest('id')
            ->paginate(30);

        return ArrayResource::collection($page->through(fn (AuditLog $log) => OrganizationController::auditRow($log)));
    }
}
