<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArrayResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        $page = User::query()
            ->with('organizations:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(20);

        return ArrayResource::collection($page->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'verified' => $user->hasVerifiedEmail(),
            'is_superadmin' => $user->is_superadmin,
            'organizations' => $user->organizations->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'role' => $o->pivot->role]),
            'created_at' => $user->created_at,
        ]));
    }
}
