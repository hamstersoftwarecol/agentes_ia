<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Team\TeamService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Equipo de la empresa: quién está y qué rol tiene. Las invitaciones viven en InvitationController. */
class TeamController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current, private readonly TeamService $team) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->current->get();
        $me = $request->user();
        $myRole = $me->roleIn($organization);
        $manages = (bool) $myRole?->canManageOrganization();

        $members = $organization->users()->orderBy('organization_user.id')->get()->map(function (User $user) use ($me, $myRole) {
            $role = OrganizationRole::from($user->pivot->role);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role->value,
                'joined_at' => $user->pivot->created_at?->toIso8601String(),
                'is_you' => $user->is($me),
                'can_edit' => ! $user->is($me) && (bool) $myRole?->canAssign($role),
            ];
        });

        // Las invitaciones (con correos de gente que aún no es parte) solo las ven quienes administran.
        $invitations = $manages
            ? Invitation::with('inviter:id,name')->orderBy('id')->get()->map(fn (Invitation $i) => InvitationController::present($i, $myRole))
            : [];

        return response()->json([
            'members' => $members,
            'invitations' => $invitations,
            'seats' => $this->team->seats($organization),
            'can_manage' => $manages,
        ]);
    }

    public function updateMember(UpdateMemberRoleRequest $request, User $member): JsonResponse
    {
        $this->team->changeRole($this->current->get(), $request->user(), $member, OrganizationRole::from($request->validated('role')));

        return response()->json(['id' => $member->id, 'role' => $request->validated('role')]);
    }

    public function removeMember(Request $request, User $member): Response
    {
        $this->team->remove($this->current->get(), $request->user(), $member);

        return response()->noContent();
    }
}
