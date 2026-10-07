<?php

namespace App\Services\Billing\Stripe;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Notifications\PaymentFailed;
use App\Services\Billing\AuditLogger;
use App\Services\Billing\BillingGate;
use App\Services\Inbox\StaffNotifier;
use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Estado de la suscripción según Stripe. Reglas:
 *  - Idempotente: Stripe reenvía eventos; cada `event.id` se procesa una sola vez.
 *  - Tolerante al desorden: un evento más antiguo que el último aplicado se ignora.
 *  - Cada evento se asocia a la empresa por sí solo (metadata → suscripción → cliente), sin depender del orden.
 */
class StripeWebhookHandler
{
    public function __construct(
        private readonly BillingGate $gate,
        private readonly AuditLogger $audit,
        private readonly StaffNotifier $notifier,
        private readonly CurrentOrganization $current,
    ) {}

    public function handle(Event $event): void
    {
        try {
            DB::transaction(function () use ($event) {
                // La restricción única hace de cerrojo: dos entregas simultáneas del mismo evento no se pisan.
                StripeEvent::create(['event_id' => $event->id, 'type' => $event->type]);

                $object = $event->data->object->toArray();

                match ($event->type) {
                    'customer.subscription.created', 'customer.subscription.updated' => $this->syncSubscription($object, $event->created),
                    'customer.subscription.deleted' => $this->syncSubscription([...$object, 'status' => 'canceled'], $event->created),
                    'checkout.session.completed' => $this->linkCheckout($object),
                    'invoice.payment_failed' => $this->paymentFailed($object),
                    default => null,
                };
            });
        } catch (UniqueConstraintViolationException) {
            // Evento ya procesado.
        }
    }

    /** @param  array<string, mixed>  $stripeSubscription */
    private function syncSubscription(array $stripeSubscription, int $eventCreated): void
    {
        $organization = $this->organizationFor($stripeSubscription);
        if (! $organization) {
            Log::warning('Stripe: suscripción sin empresa conocida', ['subscription' => $stripeSubscription['id'] ?? null]);

            return;
        }

        $current = $organization->subscription;
        if ($current?->stripe_synced_at && $eventCreated < $current->stripe_synced_at->timestamp) {
            return; // llegó tarde: ya aplicamos algo más reciente
        }

        $item = $stripeSubscription['items']['data'][0] ?? [];
        $priceId = $item['price']['id'] ?? null;
        $plan = $priceId ? Plan::where('stripe_price_id', $priceId)->first() : null;

        if (! $plan && ! $current) {
            // No sabemos qué plan es y no hay uno previo que conservar: no se inventa.
            Log::warning('Stripe: precio sin plan asociado', ['price' => $priceId]);
            $this->audit->log('stripe.unknown_price', $organization, null, null, ['price' => $priceId]);

            return;
        }

        // El período vive en el ítem en las versiones recientes de la API; antes estaba en la suscripción.
        $periodStart = $item['current_period_start'] ?? $stripeSubscription['current_period_start'] ?? null;
        $periodEnd = $item['current_period_end'] ?? $stripeSubscription['current_period_end'] ?? null;
        $cancelsAt = $stripeSubscription['cancel_at'] ?? null;

        $status = SubscriptionStatus::fromStripe((string) ($stripeSubscription['status'] ?? ''));
        $previousPlan = $current?->plan_id;

        $subscription = Subscription::updateOrCreate(['organization_id' => $organization->id], [
            'plan_id' => $plan?->id ?? $current->plan_id,
            'status' => $status,
            'source' => 'stripe',
            'stripe_customer_id' => $stripeSubscription['customer'] ?? $current?->stripe_customer_id,
            'stripe_subscription_id' => $stripeSubscription['id'] ?? $current?->stripe_subscription_id,
            'trial_ends_at' => $this->time($stripeSubscription['trial_end'] ?? null),
            'current_period_start' => $this->time($periodStart),
            'current_period_end' => $this->time($periodEnd),
            'cancel_at_period_end' => (bool) ($stripeSubscription['cancel_at_period_end'] ?? false) || $cancelsAt !== null,
            'canceled_at' => $this->time($stripeSubscription['canceled_at'] ?? null),
            'stripe_synced_at' => $this->time($eventCreated),
        ]);

        $this->gate->forget($organization);

        if (! $current || $current->status !== $status || $previousPlan !== $subscription->plan_id) {
            $this->audit->log('subscription.synced', $organization, null, $subscription, [
                'status' => $status->value, 'plan_id' => $subscription->plan_id,
            ]);
        }
    }

    /** @param  array<string, mixed>  $session */
    private function linkCheckout(array $session): void
    {
        if (($session['mode'] ?? null) !== 'subscription') {
            return;
        }

        $organization = isset($session['client_reference_id']) ? Organization::find($session['client_reference_id']) : null;
        $subscription = $organization?->subscription;

        // Si ya existe la fila (prueba en curso) se enlaza el cliente; si no, la creará el evento de la suscripción.
        $subscription?->forceFill(array_filter([
            'stripe_customer_id' => $session['customer'] ?? null,
            'stripe_subscription_id' => $session['subscription'] ?? null,
        ]))->save();
    }

    /** @param  array<string, mixed>  $invoice */
    private function paymentFailed(array $invoice): void
    {
        $subscription = isset($invoice['customer'])
            ? Subscription::where('stripe_customer_id', $invoice['customer'])->first()
            : null;
        $organization = $subscription?->organization;
        if (! $organization) {
            return;
        }

        $this->current->set($organization);
        $this->notifier->billing(new PaymentFailed($organization->name));
        $this->audit->log('stripe.payment_failed', $organization, null, $subscription, ['invoice' => $invoice['id'] ?? null]);
    }

    /** @param  array<string, mixed>  $stripeSubscription */
    private function organizationFor(array $stripeSubscription): ?Organization
    {
        if ($id = $stripeSubscription['metadata']['organization_id'] ?? null) {
            return Organization::find($id);
        }

        $known = Subscription::query()
            ->when($stripeSubscription['id'] ?? null, fn ($q, $id) => $q->orWhere('stripe_subscription_id', $id))
            ->when($stripeSubscription['customer'] ?? null, fn ($q, $customer) => $q->orWhere('stripe_customer_id', $customer))
            ->first();

        return $known?->organization;
    }

    private function time(int|string|null $timestamp): ?CarbonImmutable
    {
        return $timestamp ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }
}
