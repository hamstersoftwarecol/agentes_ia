<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\InviteMemberRequest;
use App\Models\Invitation;
use App\Services\Team\TeamService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Invitaciones pendientes de la empresa actual (las crea y gestiona quien administra el equipo). */
class InvitationController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current, private readonly TeamService $team) {}

    public function store(InviteMemberRequest $request): JsonResponse
    {
        return $this->issued(fn () => $this->team->invite(
            $this->current->get(), $request->user(), $request->validated('email'), OrganizationRole::from($request->validated('role')),
        ), $request, 201);
    }

    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        return $this->issued(fn () => $this->team->resend($this->current->get(), $request->user(), $invitation), $request, 200);
    }

    public function destroy(Request $request, Invitation $invitation): Response
    {
        $this->team->revoke($this->current->get(), $request->user(), $invitation);

        return response()->noContent();
    }

    /**
     * Crea o renueva y envía el correo. El enlace NO se devuelve a quien invita: el token es la prueba de que quien
     * se une controla ese buzón (el registro por invitación deja el correo verificado). Si lo viera quien invita
     * podría crear cuentas verificadas para correos que no controla. Si el correo falla, `emailed` es false y se reenvía.
     *
     * @param  callable(): array{0: Invitation, 1: string}  $issue
     */
    private function issued(callable $issue, Request $request, int $status): JsonResponse
    {
        [$invitation, $token] = $issue();
        $organization = $this->current->get();

        return response()->json([
            'invitation' => self::present($invitation->load('inviter:id,name'), $request->user()->roleIn($organization)),
            'emailed' => $this->team->sendInvitation($invitation, $token, $organization, $request->user()),
        ], $status);
    }

    /** @return array<string, mixed> */
    public static function present(Invitation $invitation, ?OrganizationRole $viewerRole): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'invited_by' => $invitation->inviter?->name,
            'created_at' => $invitation->created_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'expired' => $invitation->isExpired(),
            'can_edit' => (bool) $viewerRole?->canAssign(OrganizationRole::from($invitation->role)),
        ];
    }
}
