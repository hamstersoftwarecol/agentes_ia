<?php

namespace App\Services\Inbox;

use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Lead;
use App\Notifications\ConversationNeedsHuman;
use App\Notifications\NewLeadCaptured;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Notification;

/** Avisos al equipo de la empresa actual. Solo se llama cuando algo cambia (no en cada mensaje). */
class StaffNotifier
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function handoffRequested(Conversation $conversation): void
    {
        $this->send(OrganizationRole::cases(), new ConversationNeedsHuman($conversation->loadMissing('contact')));
    }

    public function leadCaptured(Lead $lead): void
    {
        $this->send([OrganizationRole::Owner, OrganizationRole::Admin], new NewLeadCaptured($lead));
    }

    /** Avisos de facturación (cupo, cobro fallido): solo a quien administra la cuenta. */
    public function billing(object $notification): void
    {
        $this->send([OrganizationRole::Owner, OrganizationRole::Admin], $notification);
    }

    /** @param  list<OrganizationRole>  $roles */
    private function send(array $roles, object $notification): void
    {
        $organization = $this->current->get();
        if (! config('inbox.notify_by_email') || ! $organization) {
            return;
        }

        $recipients = $organization->users()
            ->wherePivotIn('role', array_map(fn (OrganizationRole $r) => $r->value, $roles))
            ->get();

        Notification::send($recipients, $notification);
    }
}
