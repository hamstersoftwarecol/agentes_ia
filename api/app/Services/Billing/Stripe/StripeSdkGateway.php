<?php

namespace App\Services\Billing\Stripe;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use RuntimeException;
use Stripe\StripeClient;

class StripeSdkGateway implements StripeGateway
{
    public function __construct(private readonly ?StripeClient $client = null) {}

    public function configured(): bool
    {
        return $this->client !== null || filled(config('billing.stripe.secret'));
    }

    public function checkoutUrl(Organization $organization, Plan $plan, User $user, ?string $customerId): string
    {
        $frontend = rtrim(config('app.frontend_url'), '/');
        $reference = (string) $organization->id;

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
            // La empresa viaja en la sesión y en la suscripción: los webhooks la reconocen sin depender del orden de llegada.
            'client_reference_id' => $reference,
            'metadata' => ['organization_id' => $reference],
            'subscription_data' => ['metadata' => ['organization_id' => $reference]],
            'allow_promotion_codes' => true,
            'success_url' => "{$frontend}/billing?checkout=success",
            'cancel_url' => "{$frontend}/billing?checkout=canceled",
        ] + ($customerId ? ['customer' => $customerId] : ['customer_email' => $user->email]));

        return $session->url ?? throw new RuntimeException('Stripe no devolvió la URL de pago.');
    }

    public function portalUrl(string $customerId): string
    {
        $session = $this->client()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => rtrim(config('app.frontend_url'), '/').'/billing',
        ]);

        return $session->url ?? throw new RuntimeException('Stripe no devolvió la URL del portal.');
    }

    private function client(): StripeClient
    {
        return $this->client ?? new StripeClient((string) config('billing.stripe.secret'));
    }
}
