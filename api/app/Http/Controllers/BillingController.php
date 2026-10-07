<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Enums\SubscriptionStatus;
use App\Http\Resources\PlanResource;
use App\Models\Agent;
use App\Models\Plan;
use App\Services\Billing\BillingGate;
use App\Services\Billing\Stripe\StripeGateway;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BillingController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly BillingGate $gate,
        private readonly StripeGateway $stripe,
    ) {}

    /** Plan actual, consumo del período y planes disponibles. Cualquier miembro puede verlo. */
    public function show(Request $request): JsonResponse
    {
        $organization = $this->current->get();
        $entitlements = $this->gate->for($organization);
        $subscription = $entitlements->subscription;

        return response()->json([
            'plan' => $entitlements->plan ? new PlanResource($entitlements->plan) : null,
            'state' => $entitlements->state,
            'status' => $subscription?->status->value,
            'source' => $subscription?->source,
            'trial_ends_at' => $subscription?->status === SubscriptionStatus::Trialing ? $subscription->trial_ends_at : null,
            'period_start' => $entitlements->periodStart,
            'period_end' => $entitlements->periodEnd,
            'cancel_at_period_end' => (bool) $subscription?->cancel_at_period_end,
            'usage' => [
                'messages_used' => $entitlements->messagesUsed(),
                'messages_limit' => $entitlements->monthlyMessages(),
                'percent' => min(100, $entitlements->usagePercent()),
            ],
            'agents' => ['active' => Agent::where('is_active', true)->count(), 'limit' => (int) $entitlements->plan?->max_agents],
            'users' => ['members' => $organization->users()->count(), 'limit' => $entitlements->maxUsers()],
            'suspension_reason' => $organization->suspension_reason,
            'stripe' => [
                'configured' => $this->stripe->configured(),
                'has_customer' => filled($subscription?->stripe_customer_id),
                'paying' => $subscription?->source === 'stripe' && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true),
            ],
            'can_manage' => $this->isOwner($request),
            'plans' => PlanResource::collection(Plan::where('is_active', true)->orderBy('sort_order')->get()),
        ]);
    }

    /** Contratar un plan: lleva a Stripe Checkout. Si ya paga una suscripción, el cambio de plan es en el portal. */
    public function checkout(Request $request): JsonResponse
    {
        abort_unless($this->isOwner($request), 403, 'Solo el propietario de la empresa puede gestionar la facturación.');
        $data = $request->validate(['plan' => ['required', 'string']]);

        if (! $this->stripe->configured()) {
            return response()->json(['message' => 'Los pagos todavía no están configurados.', 'code' => 'billing_unavailable'], 503);
        }

        $plan = Plan::where('slug', $data['plan'])->where('is_active', true)->first();
        if (! $plan || ! $plan->stripe_price_id) {
            return response()->json(['message' => 'Ese plan no está disponible para contratar todavía.'], 422);
        }

        $subscription = $this->current->get()->subscription;

        try {
            if ($subscription?->source === 'stripe' && $subscription->stripe_customer_id && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Trialing], true)) {
                return response()->json(['url' => $this->stripe->portalUrl($subscription->stripe_customer_id), 'via' => 'portal']);
            }

            return response()->json([
                'url' => $this->stripe->checkoutUrl($this->current->get(), $plan, $request->user(), $subscription?->stripe_customer_id),
                'via' => 'checkout',
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No pudimos iniciar el pago. Inténtalo de nuevo en unos minutos.'], 502);
        }
    }

    /** Portal de Stripe: método de pago, facturas, cancelar. */
    public function portal(Request $request): JsonResponse
    {
        abort_unless($this->isOwner($request), 403, 'Solo el propietario de la empresa puede gestionar la facturación.');

        $customerId = $this->current->get()->subscription?->stripe_customer_id;
        if (! $this->stripe->configured() || ! $customerId) {
            return response()->json(['message' => 'Todavía no tienes una suscripción de pago que administrar.'], 422);
        }

        try {
            return response()->json(['url' => $this->stripe->portalUrl($customerId)]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No pudimos abrir el portal de facturación. Inténtalo de nuevo en unos minutos.'], 502);
        }
    }

    private function isOwner(Request $request): bool
    {
        return $request->user()->roleIn($this->current->get()) === OrganizationRole::Owner;
    }
}
