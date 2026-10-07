<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterViaInvitationRequest;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Team\TeamException;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Lo que hace quien recibe el enlace de invitación: verla, crear su cuenta o aceptarla con la que ya tiene. */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    /** Vista previa pública (quien tiene el token ya recibió el correo: sabe el correo y la empresa). */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $organization = Organization::findOrFail($invitation->organization_id);

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'organization' => ['name' => $organization->name, 'logo_url' => $organization->logo_url],
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'account_exists' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    /** Alta de quien aún no tiene cuenta: queda dentro de la empresa y con la sesión iniciada. */
    public function register(RegisterViaInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->team->registerAndAccept($this->find($token), $request->validated('name'), $request->validated('password'));
        Auth::guard('web')->login($user);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /** Quien ya tiene cuenta (y la sesión iniciada con ese correo) se une a la empresa. */
    public function accept(Request $request, string $token): UserResource
    {
        $user = $request->user();
        $this->team->accept($this->find($token), $user);

        return new UserResource($user->fresh());
    }

    private function find(string $token): Invitation
    {
        $invitation = Invitation::findByToken($token);
        if (! $invitation) {
            throw new TeamException('Esta invitación no existe o ya fue usada.', 404);
        }
        if ($invitation->isExpired()) {
            throw new TeamException('La invitación venció. Pide que te envíen una nueva.', 410);
        }

        return $invitation;
    }
}
