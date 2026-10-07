<?php

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-10-15 12:00:00 UTC'));
afterEach(fn () => Carbon::setTestNow());

function orgWithPlan(string $name, string $plan, array $subscription): Organization
{
    [, $organization] = userWithOrganization();
    $organization->forceFill(['name' => $name])->save();
    setPlan($organization, $plan, $subscription);

    return $organization;
}

test('margen = ingresos − costo de IA − infraestructura, del mes en curso', function () {
    config(['billing.infrastructure_cost_usd' => 5]);
    $a = orgWithPlan('A', 'starter', ['source' => 'stripe', 'status' => 'active']);                    // 1900
    $b = orgWithPlan('B', 'business', ['source' => 'stripe', 'status' => 'active']);                   // 4900
    orgWithPlan('C', 'pro', ['source' => 'stripe', 'status' => 'past_due']);                            // 9900 (sigue pagando)
    orgWithPlan('D', 'pro', ['source' => 'manual', 'status' => 'active']);                              // cortesía: no es ingreso
    orgWithPlan('E', 'starter', ['source' => 'trial', 'status' => 'trialing', 'trial_ends_at' => now()->addDays(3)]);
    orgWithPlan('F', 'business', ['source' => 'stripe', 'status' => 'canceled']);

    usage($a, 1, 'widget', now(), ['cost_micros' => 3_000_000, 'input_tokens' => 1000, 'output_tokens' => 100]);   // $3,00
    usage($b, 1, 'widget', now()->startOfMonth(), ['cost_micros' => 1_500_000, 'input_tokens' => 500, 'output_tokens' => 50]); // $1,50
    usage($b, 0, 'test', now(), ['cost_micros' => 500_000]);                                                      // $0,50 (las pruebas también cuestan)
    usage($a, 1, 'widget', now()->subMonth(), ['cost_micros' => 99_000_000]);                                    // mes anterior: fuera

    $response = $this->actingAs(superadmin())->getJson('/api/admin/overview')->assertOk();

    expect($response->json('mrr_cents'))->toBe(1900 + 4900 + 9900)
        ->and($response->json('ai_cost_cents'))->toBe(500)                // $5,00
        ->and($response->json('infrastructure_cost_cents'))->toBe(500)
        ->and($response->json('margin_cents'))->toBe(16700 - 500 - 500)
        ->and($response->json('month'))->toBe('2026-10-01')
        ->and($response->json('subscriptions'))->toBe(['paying' => 3, 'trialing' => 1, 'manual' => 1, 'past_due' => 1, 'canceled' => 1])
        ->and($response->json('messages_month'))->toBe(2)                  // solo respuestas a clientes
        ->and($response->json('tokens_month'))->toBe(1650);
});

test('un mes sin ingresos muestra el margen negativo, no lo oculta', function () {
    $a = orgWithPlan('A', 'starter', ['source' => 'trial', 'status' => 'trialing', 'trial_ends_at' => now()->addDay()]);
    usage($a, 1, 'widget', now(), ['cost_micros' => 2_000_000]);

    expect($this->actingAs(superadmin())->getJson('/api/admin/overview')->json('margin_cents'))->toBe(-200);
});

test('la serie diaria de costo tiene 30 días y termina hoy', function () {
    $a = orgWithPlan('A', 'starter', ['source' => 'manual']);
    usage($a, 1, 'widget', '2026-10-15 08:00:00', ['cost_micros' => 2_000_000]);
    usage($a, 1, 'widget', '2026-10-14 08:00:00', ['cost_micros' => 1_000_000]);
    usage($a, 1, 'widget', '2026-09-16 08:00:00', ['cost_micros' => 500_000]);
    usage($a, 1, 'widget', '2026-09-15 08:00:00', ['cost_micros' => 9_000_000]); // fuera de los 30 días

    $daily = collect($this->actingAs(superadmin())->getJson('/api/admin/overview')->json('daily'));

    expect($daily)->toHaveCount(30)
        ->and($daily->first())->toBe(['date' => '2026-09-16', 'ai_cost_cents' => 50, 'messages' => 1])
        ->and($daily->last())->toBe(['date' => '2026-10-15', 'ai_cost_cents' => 200, 'messages' => 1])
        ->and($daily->sum('ai_cost_cents'))->toBe(350);
});

test('cuenta las empresas totales y las nuevas de los últimos 30 días', function () {
    orgWithPlan('Vieja', 'starter', ['source' => 'manual']);
    Organization::query()->update(['created_at' => now()->subDays(60)]);
    orgWithPlan('Nueva', 'starter', ['source' => 'manual']);

    $response = $this->actingAs(superadmin())->getJson('/api/admin/overview');

    expect($response->json('organizations_total'))->toBe(2)->and($response->json('organizations_new_30d'))->toBe(1);
});

test('no depende de la empresa que haya quedado fijada: ve todas', function () {
    $a = orgWithPlan('A', 'starter', ['source' => 'manual']);
    $b = orgWithPlan('B', 'starter', ['source' => 'manual']);
    usage($a, 1, 'widget', now(), ['cost_micros' => 1_000_000]);
    usage($b, 1, 'widget', now(), ['cost_micros' => 1_000_000]);
    app(CurrentOrganization::class)->set($a); // estado "sucio" previo

    expect($this->actingAs(superadmin())->getJson('/api/admin/overview')->json('ai_cost_cents'))->toBe(200);
});

test('el uso de IA se desglosa por modelo, empresa y origen', function () {
    $a = orgWithPlan('Alfa', 'starter', ['source' => 'manual']);
    $b = orgWithPlan('Beta', 'starter', ['source' => 'manual']);
    usage($a, 1, 'widget', now(), ['model' => 'claude-opus-5-5', 'input_tokens' => 1000, 'output_tokens' => 100, 'cost_micros' => 6_000]);
    usage($a, 1, 'widget', now(), ['model' => 'claude-opus-5-5', 'input_tokens' => 1000, 'output_tokens' => 100, 'cost_micros' => 6_000]);
    usage($b, 1, 'widget', now(), ['model' => 'claude-haiku-4-5', 'input_tokens' => 2000, 'output_tokens' => 200, 'cost_micros' => 3_000]);
    usage($b, 0, 'test', now(), ['model' => 'claude-haiku-4-5', 'cost_micros' => 1_000]);
    usage($a, 0, 'document', now(), ['type' => 'embedding', 'model' => 'text-embedding-3-small', 'input_tokens' => 5000, 'cost_micros' => 100]);
    usage($a, 1, 'widget', now()->subDays(45), ['model' => 'claude-opus-5-5', 'cost_micros' => 999_999]); // fuera de 30 días

    $response = $this->actingAs(superadmin())->getJson('/api/admin/usage')->assertOk();

    $byModel = collect($response->json('by_model'))->keyBy(fn ($r) => $r['model'].'/'.$r['type']);
    expect($byModel['claude-opus-5-5/chat']['cost_micros'])->toBe(12_000)
        ->and($byModel['claude-opus-5-5/chat']['messages'])->toBe(2)
        ->and($byModel['claude-opus-5-5/chat']['calls'])->toBe(2)
        ->and($byModel['claude-haiku-4-5/chat']['cost_micros'])->toBe(4_000)
        ->and($byModel['text-embedding-3-small/embedding']['input_tokens'])->toBe(5000)
        ->and(collect($response->json('by_model'))->pluck('cost_micros')->all())->toBe([12_000, 4_000, 100]); // más caro primero

    $byOrg = collect($response->json('by_organization'));
    expect($byOrg->pluck('name')->all())->toBe(['Alfa', 'Beta'])->and($byOrg->first()['cost_micros'])->toBe(12_100);

    expect(collect($response->json('by_source'))->pluck('source')->all())->toBe(['widget', 'test', 'document']);
});

test('el rango del uso se puede acotar y se valida', function () {
    $a = orgWithPlan('A', 'starter', ['source' => 'manual']);
    usage($a, 1, 'widget', now()->subDays(5), ['model' => 'claude-opus-5-5', 'cost_micros' => 1000]);

    $admin = superadmin();
    expect($this->actingAs($admin)->getJson('/api/admin/usage?days=3')->json('by_model'))->toBe([])
        ->and($this->actingAs($admin)->getJson('/api/admin/usage?days=7')->json('by_model'))->toHaveCount(1);
    $this->actingAs($admin)->getJson('/api/admin/usage?days=0')->assertJsonValidationErrors('days');
    $this->actingAs($admin)->getJson('/api/admin/usage?days=1000')->assertJsonValidationErrors('days');
});

test('la configuración se muestra sin revelar ningún secreto', function () {
    config([
        'ai.providers.anthropic.key' => 'sk-ant-SECRETO-123',
        'ai.embeddings.providers.openai.key' => 'sk-openai-SECRETO-456',
        'billing.stripe.secret' => 'sk_live_SECRETO_789',
        'billing.stripe.webhook_secret' => 'whsec_SECRETO_000',
    ]);

    $response = $this->actingAs(superadmin())->getJson('/api/admin/settings')->assertOk();

    expect($response->getContent())->not->toContain('SECRETO')
        ->and($response->json('ai.anthropic.key_set'))->toBeTrue()
        ->and($response->json('ai.openai.key_set'))->toBeTrue()
        ->and($response->json('billing.stripe_secret_set'))->toBeTrue()
        ->and($response->json('billing.stripe_webhook_secret_set'))->toBeTrue()
        ->and($response->json('ai.pricing'))->toHaveKey('claude-opus-5-5')
        ->and($response->json('billing.trial_days'))->toBe(14);
});

test('sin claves configuradas la configuración lo dice', function () {
    config(['ai.providers.anthropic.key' => null, 'billing.stripe.secret' => null]);

    $response = $this->actingAs(superadmin())->getJson('/api/admin/settings');

    expect($response->json('ai.anthropic.key_set'))->toBeFalse()->and($response->json('billing.stripe_secret_set'))->toBeFalse();
});
