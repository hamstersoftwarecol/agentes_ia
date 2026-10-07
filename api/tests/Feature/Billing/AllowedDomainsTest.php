<?php

use App\Models\Agent;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Plan;
use App\Support\CurrentOrganization;

function domainsOrg(string $plan = 'pro'): array
{
    [$user, $organization] = userWithOrganization();
    setPlan($organization, $plan, ['source' => 'manual', 'current_period_end' => null]);

    return [$user, $organization];
}

function agentWithDomains(Organization $organization, array $domains): Agent
{
    app(CurrentOrganization::class)->set($organization);

    return Agent::factory()->create(['allowed_domains' => $domains]);
}

function configFrom(Agent $agent, ?string $origin)
{
    return test()->getJson("/api/widget/{$agent->public_id}/config", $origin ? ['Origin' => $origin] : []);
}

// ---------- configuración desde el panel ----------

test('los dominios se normalizan: sin esquema, ruta, puerto, www ni comodín, en minúsculas y sin repetir', function () {
    [$user, $organization] = domainsOrg();
    $agent = createAgentIn($organization);

    $response = $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => [
        'https://www.MiTienda.com/ruta?x=1', 'tienda.co:8080', '*.otro.com', 'mitienda.com', '  ', 'LOCALHOST', '192.168.1.10',
    ]])->assertOk();

    expect($response->json('allowed_domains'))->toBe(['mitienda.com', 'tienda.co', 'otro.com', 'localhost', '192.168.1.10']);
});

test('rechaza valores que no son un dominio', function (string $bad) {
    [$user, $organization] = domainsOrg();
    $agent = createAgentIn($organization);

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => [$bad]])->assertJsonValidationErrors('allowed_domains.0');
})->with(['no es un dominio', 'a_b.com', 'foo', 'http://', '-malo.com', 'dominio..com', 'javascript:alert(1)', '<script>.com']);

test('hay un máximo de 10 dominios por agente', function () {
    [$user, $organization] = domainsOrg();
    $agent = createAgentIn($organization);
    $domains = collect(range(1, 11))->map(fn ($i) => "sitio{$i}.com")->all();

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => $domains])->assertJsonValidationErrors('allowed_domains');
    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => array_slice($domains, 0, 10)])->assertOk();
});

test('el plan limita cuántos sitios distintos tiene la empresa en total', function () {
    [$user, $organization] = domainsOrg('starter'); // 1 sitio
    $a = createAgentIn($organization);
    $b = createAgentIn($organization);

    $this->actingAs($user)->patchJson("/api/agents/{$a->id}", ['allowed_domains' => ['uno.com']])->assertOk();
    // El mismo sitio en otro agente no es un sitio nuevo.
    $this->actingAs($user)->patchJson("/api/agents/{$b->id}", ['allowed_domains' => ['uno.com']])->assertOk();
    // Un segundo sitio, sí.
    $response = $this->actingAs($user)->patchJson("/api/agents/{$b->id}", ['allowed_domains' => ['dos.com']])->assertUnprocessable();
    expect($response->json('errors.allowed_domains.0'))->toContain('1 sitio web')->toContain('sube de plan');
    $this->actingAs($user)->patchJson("/api/agents/{$a->id}", ['allowed_domains' => ['uno.com', 'dos.com']])->assertJsonValidationErrors('allowed_domains');
});

test('Business permite 3 sitios y Pro 10', function () {
    [$business, $businessOrg] = domainsOrg('business');
    $agent = createAgentIn($businessOrg);

    $this->actingAs($business)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => ['a.com', 'b.com', 'c.com']])->assertOk();
    $this->actingAs($business)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => ['a.com', 'b.com', 'c.com', 'd.com']])->assertJsonValidationErrors('allowed_domains');
});

test('un plan sin tope de sitios no limita la cantidad', function () {
    [$user, $organization] = domainsOrg('pro');
    Plan::where('slug', 'pro')->update(['max_sites' => null]);
    app()->forgetScopedInstances();
    $agent = createAgentIn($organization);

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['allowed_domains' => collect(range(1, 10))->map(fn ($i) => "s$i.com")->all()])->assertOk();
});

test('actualizar otros campos no toca los dominios', function () {
    [$user, $organization] = domainsOrg();
    $agent = agentWithDomains($organization, ['mitienda.com']);

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['name' => 'Otro'])->assertOk()->assertJsonPath('allowed_domains', ['mitienda.com']);
});

// ---------- aplicación en el widget ----------

test('el widget solo se muestra en los sitios permitidos y sus subdominios', function (string $origin, bool $allowed) {
    [, $organization] = domainsOrg();
    $agent = agentWithDomains($organization, ['mitienda.com']);

    $response = configFrom($agent, $origin)->assertOk();

    expect($response->json('active'))->toBe($allowed);
    if (! $allowed) {
        expect($response->json())->toBe(['active' => false, 'reason' => 'origin']);
    }
})->with([
    'el dominio' => ['https://mitienda.com', true],
    'con www' => ['https://www.mitienda.com', true],
    'otro subdominio' => ['https://tienda.mitienda.com', true],
    'con puerto' => ['http://mitienda.com:8080', true],
    'distinto' => ['https://otro.com', false],
    'termina igual pero es otro dominio' => ['https://evilmitienda.com', false],
    'lo contiene como subdominio ajeno' => ['https://mitienda.com.evil.io', false],
    'origen opaco' => ['null', false],
    'esquema raro' => ['chrome-extension://abc', false],
]);

test('sin cabecera Origin se permite (mismo origen o fuera de un navegador)', function () {
    [, $organization] = domainsOrg();
    $agent = agentWithDomains($organization, ['mitienda.com']);

    expect(configFrom($agent, null)->json('active'))->toBeTrue();
});

test('un agente sin dominios solo se muestra en el propio panel (vista previa)', function () {
    [, $organization] = domainsOrg();
    $agent = agentWithDomains($organization, []);

    expect(configFrom($agent, 'https://cualquiera.com')->json('active'))->toBeFalse()
        ->and(configFrom($agent, config('app.frontend_url'))->json('active'))->toBeTrue();
});

test('un sitio no permitido no puede enviar mensajes ni leer el historial', function () {
    [, $organization] = domainsOrg();
    $agent = agentWithDomains($organization, ['mitienda.com']);
    $ai = fakeAI();
    $headers = fn (string $origin) => ['X-Visitor-Id' => VISITOR, 'Origin' => $origin];

    // Desde el sitio permitido todo funciona…
    $id = test()->postJson("/api/widget/{$agent->public_id}/messages", ['message' => 'hola'], $headers('https://mitienda.com'))->assertCreated()->json('conversation_id');
    test()->getJson("/api/widget/{$agent->public_id}/conversations/{$id}/messages", $headers('https://www.mitienda.com'))->assertOk();

    // …desde otro sitio, no.
    test()->postJson("/api/widget/{$agent->public_id}/messages", ['message' => 'robo de cupo'], $headers('https://evil.com'))
        ->assertForbidden()->assertJsonPath('code', 'origin_not_allowed');
    test()->getJson("/api/widget/{$agent->public_id}/conversations/{$id}/messages", $headers('https://evil.com'))
        ->assertForbidden()->assertJsonPath('code', 'origin_not_allowed');

    expect(count($ai->calls))->toBe(1)->and(Message::withoutGlobalScopes()->count())->toBe(2);
});

test('el rechazo por sitio no permitido también lleva cabeceras CORS (el navegador puede leer el motivo)', function () {
    [, $organization] = domainsOrg();
    $agent = agentWithDomains($organization, ['mitienda.com']);

    $response = test()->postJson("/api/widget/{$agent->public_id}/messages", ['message' => 'x'], ['X-Visitor-Id' => VISITOR, 'Origin' => 'https://evil.com']);

    expect($response->getStatusCode())->toBe(403)->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});
