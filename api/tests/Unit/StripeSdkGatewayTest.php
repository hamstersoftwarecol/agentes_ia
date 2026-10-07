<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\Stripe\StripeSdkGateway;
use Stripe\ApiRequestor;
use Stripe\Exception\AuthenticationException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Tests\TestCase;

uses(TestCase::class);

/** Cliente HTTP falso del SDK de Stripe: guarda las peticiones y devuelve respuestas fijas. */
class FakeStripeHttp implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'params');
        [$status, $body] = array_shift($this->responses) ?? [500, ['error' => ['message' => 'sin respuesta']]];

        return [json_encode($body), $status, []];
    }
}

afterEach(fn () => ApiRequestor::setHttpClient(CurlClient::instance()));

function gatewayWith(array $responses): array
{
    $http = new FakeStripeHttp($responses);
    ApiRequestor::setHttpClient($http);

    return [new StripeSdkGateway(new StripeClient('sk_test_fake')), $http];
}

function org(): Organization
{
    return (new Organization)->forceFill(['id' => 42, 'name' => 'Barbería XYZ']);
}

test('crea una sesión de Checkout de suscripción con la empresa en la sesión y en la suscripción', function () {
    [$gateway, $http] = gatewayWith([[200, ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']]]);
    $plan = (new Plan)->forceFill(['slug' => 'business', 'stripe_price_id' => 'price_business']);
    $user = (new User)->forceFill(['email' => 'ale@example.com']);

    $url = $gateway->checkoutUrl(org(), $plan, $user, null);

    $request = $http->requests[0];
    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_1')
        ->and($request['method'])->toBe('post')
        ->and($request['absUrl'])->toEndWith('/v1/checkout/sessions')
        ->and($request['params']['mode'])->toBe('subscription')
        ->and($request['params']['line_items'])->toBe([['price' => 'price_business', 'quantity' => 1]])
        ->and($request['params']['client_reference_id'])->toBe('42')
        ->and($request['params']['metadata'])->toBe(['organization_id' => '42'])
        ->and($request['params']['subscription_data'])->toBe(['metadata' => ['organization_id' => '42']])
        ->and($request['params']['customer_email'])->toBe('ale@example.com')
        ->and($request['params'])->not->toHaveKey('customer')
        ->and($request['params']['allow_promotion_codes'])->toBe('true') // el SDK serializa los booleanos como texto
        ->and($request['params']['success_url'])->toBe(config('app.frontend_url').'/billing?checkout=success')
        ->and($request['params']['cancel_url'])->toBe(config('app.frontend_url').'/billing?checkout=canceled');
});

test('si ya hay cliente en Stripe se usa en vez del correo', function () {
    [$gateway, $http] = gatewayWith([[200, ['id' => 'cs_2', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_2']]]);
    $plan = (new Plan)->forceFill(['slug' => 'pro', 'stripe_price_id' => 'price_pro']);

    $gateway->checkoutUrl(org(), $plan, (new User)->forceFill(['email' => 'a@b.co']), 'cus_existente');

    expect($http->requests[0]['params']['customer'])->toBe('cus_existente')->and($http->requests[0]['params'])->not->toHaveKey('customer_email');
});

test('crea una sesión del portal de facturación que vuelve a /billing', function () {
    [$gateway, $http] = gatewayWith([[200, ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/bps_1']]]);

    $url = $gateway->portalUrl('cus_123');

    expect($url)->toBe('https://billing.stripe.com/p/session/bps_1')
        ->and($http->requests[0]['absUrl'])->toEndWith('/v1/billing_portal/sessions')
        ->and($http->requests[0]['params'])->toBe(['customer' => 'cus_123', 'return_url' => config('app.frontend_url').'/billing']);
});

test('un error de Stripe se propaga para que el controlador lo reporte', function () {
    [$gateway] = gatewayWith([[401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key']]]]);

    $gateway->portalUrl('cus_123');
})->throws(AuthenticationException::class);

test('sin clave secreta el gateway se declara no configurado', function () {
    config(['billing.stripe.secret' => null]);

    expect((new StripeSdkGateway)->configured())->toBeFalse();

    config(['billing.stripe.secret' => 'sk_test_x']);
    expect((new StripeSdkGateway)->configured())->toBeTrue();
});
