<?php

namespace App\Services\Team;

use App\Enums\ConversationStatus;
use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Services\Billing\AuditLogger;
use App\Services\Billing\BillingGate;
use App\Services\Billing\PlanLimitReached;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Personas de una empresa: quién está, a quién se invita y con qué rol.
 *
 * Reglas: el propietario gestiona administradores y agentes, un administrador solo agentes, nadie toca al
 * propietario ni a sí mismo (ver OrganizationRole::canAssign). Las plazas se cuentan como miembros + invitaciones
 * vigentes y el tope sale del plan.
 */
class TeamService
{
    public function __construct(private readonly BillingGate $gate, private readonly AuditLogger $audit) {}

    /** @return array{members: int, pending: int, used: int, limit: int} */
    public function seats(Organization $organization): array
    {
        $members = $organization->users()->count();
        $pending = Invitation::withoutGlobalScopes()->where('organization_id', $organization->id)->vigentes()->count();

        return ['members' => $members, 'pending' => $pending, 'used' => $members + $pending, 'limit' => $this->gate->for($organization)->maxUsers()];
    }

    /**
     * Crea la invitación (o renueva la que ya había para ese correo). Devuelve también el token en claro:
     * es la única vez que existe fuera del correo.
     *
     * @return array{0: Invitation, 1: string}
     */
    public function invite(Organization $organization, User $actor, string $email, OrganizationRole $role): array
    {
        $this->assertCanAssign($organization, $actor, $role);

        $entitlements = $this->gate->for($organization);
        if (! $entitlements->serves()) {
            throw PlanLimitReached::inactive($entitlements);
        }

        $email = Str::lower(trim($email));
        if ($organization->users()->where('users.email', $email)->exists()) {
            throw new TeamException('Esa persona ya forma parte del equipo.', 422, 'email');
        }

        return DB::transaction(function () use ($organization, $actor, $email, $role) {
            // Serializa las invitaciones simultáneas de una misma empresa para no pasarse del tope de plazas.
            Organization::whereKey($organization->id)->lockForUpdate()->first();

            $existing = Invitation::withoutGlobalScopes()->where('organization_id', $organization->id)->where('email', $email)->first();
            if ((! $existing || $existing->isExpired()) && $this->seats($organization)['used'] >= $this->gate->for($organization)->maxUsers()) {
                throw PlanLimitReached::users($this->gate->for($organization));
            }

            $invitation = $existing ?? new Invitation(['email' => $email]);
            $invitation->organization_id = $organization->id;
            [$token] = $this->renew($invitation, $actor, $role);

            $this->audit->log($existing ? 'team.invitation_renewed' : 'team.invited', $organization, $actor, $invitation, ['email' => $email, 'role' => $role->value]);

            return [$invitation, $token];
        });
    }

    /** Nuevo token y nueva fecha de vencimiento para una invitación existente. */
    public function resend(Organization $organization, User $actor, Invitation $invitation): array
    {
        return $this->invite($organization, $actor, $invitation->email, OrganizationRole::from($invitation->role));
    }

    public function revoke(Organization $organization, User $actor, Invitation $invitation): void
    {
        $this->assertCanAssign($organization, $actor, OrganizationRole::from($invitation->role));

        $invitation->delete();
        $this->audit->log('team.invitation_revoked', $organization, $actor, null, ['email' => $invitation->email]);
    }

    public function changeRole(Organization $organization, User $actor, User $member, OrganizationRole $role): void
    {
        $current = $this->memberRole($organization, $member);
        if ($member->is($actor)) {
            throw new TeamException('No puedes cambiar tu propio rol.', 403);
        }
        $this->assertCanAssign($organization, $actor, $current);
        $this->assertCanAssign($organization, $actor, $role);

        if ($current === $role) {
            return;
        }

        $organization->users()->updateExistingPivot($member->id, ['role' => $role->value]);
        $this->audit->log('team.role_changed', $organization, $actor, $member, ['from' => $current->value, 'to' => $role->value]);
    }

    /** Quita a alguien del equipo. Sus conversaciones en curso vuelven a «esperando» para que otra persona las tome. */
    public function remove(Organization $organization, User $actor, User $member): void
    {
        $role = $this->memberRole($organization, $member);
        if ($member->is($actor)) {
            throw new TeamException('No puedes quitarte a ti mismo del equipo.', 403);
        }
        $this->assertCanAssign($organization, $actor, $role);

        DB::transaction(function () use ($organization, $actor, $member, $role) {
            $organization->users()->detach($member->id);

            if ($member->current_organization_id === $organization->id) {
                $member->forceFill(['current_organization_id' => null])->save();
            }

            Conversation::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('assigned_user_id', $member->id)
                ->where('status', ConversationStatus::Human->value)
                ->update(['status' => ConversationStatus::Waiting->value, 'assigned_user_id' => null]);

            $this->audit->log('team.member_removed', $organization, $actor, $member, ['role' => $role->value]);
        });
    }

    /** Quien tiene el enlace se une a la empresa con el rol de la invitación. */
    public function accept(Invitation $invitation, User $user): Organization
    {
        if ($invitation->isExpired()) {
            throw new TeamException('La invitación venció. Pide que te envíen una nueva.', 410);
        }
        if (Str::lower($user->email) !== $invitation->email) {
            throw new TeamException("Esta invitación es para {$invitation->email}. Entra con ese correo para aceptarla.", 403);
        }

        $organization = Organization::findOrFail($invitation->organization_id);

        if ($organization->users()->whereKey($user->id)->exists()) {
            $invitation->delete();

            return $organization;
        }
        if ($user->organizations()->exists()) {
            throw new TeamException('Tu cuenta ya pertenece a otra empresa. Pide que te inviten con otro correo.', 409);
        }

        return DB::transaction(function () use ($invitation, $user, $organization) {
            Organization::whereKey($organization->id)->lockForUpdate()->first();

            $entitlements = $this->gate->for($organization);
            if ($organization->users()->count() >= $entitlements->maxUsers()) {
                throw PlanLimitReached::users($entitlements);
            }

            $organization->users()->attach($user, ['role' => $invitation->role]);
            $user->forceFill(['current_organization_id' => $organization->id])->save();
            $invitation->delete();

            $this->audit->log('team.invitation_accepted', $organization, $user, $user, ['role' => $invitation->role]);

            return $organization;
        });
    }

    /** Crea la cuenta de quien llega por invitación (el correo queda verificado: el enlace llegó a esa bandeja) y lo une. */
    public function registerAndAccept(Invitation $invitation, string $name, string $password): User
    {
        if ($invitation->isExpired()) {
            throw new TeamException('La invitación venció. Pide que te envíen una nueva.', 410);
        }
        if (User::where('email', $invitation->email)->exists()) {
            throw new TeamException('Ya existe una cuenta con este correo. Inicia sesión para aceptar la invitación.', 409);
        }

        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
            $user->email_verified_at = now();
            $user->save();

            $this->accept($invitation, $user);

            return $user;
        });
    }

    /** Envía el correo; si falla, la invitación sigue creada y se puede compartir el enlace. */
    public function sendInvitation(Invitation $invitation, string $token, Organization $organization, User $inviter): bool
    {
        try {
            Notification::route('mail', $invitation->email)->notify(new TeamInvitation(
                $organization->name,
                $inviter->name,
                OrganizationRole::from($invitation->role)->label(),
                $this->link($token),
                $invitation->expires_at,
            ));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function link(string $token): string
    {
        return rtrim(config('app.frontend_url'), '/').'/invitations/'.$token;
    }

    /** @return array{0: string} */
    private function renew(Invitation $invitation, User $actor, OrganizationRole $role): array
    {
        $token = Str::random(48);

        $invitation->forceFill([
            'role' => $role->value,
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays((int) config('team.invitation_days')),
        ])->save();

        return [$token];
    }

    private function memberRole(Organization $organization, User $member): OrganizationRole
    {
        $role = $organization->roleOf($member);
        if (! $role) {
            throw new TeamException('Esa persona no pertenece a tu equipo.', 404);
        }

        return $role;
    }

    private function assertCanAssign(Organization $organization, User $actor, OrganizationRole $target): void
    {
        if (! $actor->roleIn($organization)?->canAssign($target)) {
            throw new TeamException('Tu rol no permite hacer esto con ese rol.', 403);
        }
    }
}
