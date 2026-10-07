<?php

namespace App\Http\Controllers;

use App\Services\Billing\Stripe\StripeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): JsonResponse
    {
        $secret = config('billing.stripe.webhook_secret');
        if (! $secret) {
            return response()->json(['message' => 'Webhook de Stripe sin configurar.'], 503);
        }

        try {
            // La firma se verifica sobre el cuerpo CRUDO: reserializar el JSON la invalidaría.
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Firma inválida.'], 400);
        }

        $handler->handle($event);

        return response()->json(['received' => true]);
    }
}
