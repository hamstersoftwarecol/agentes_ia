<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('usd');
            $table->string('stripe_price_id')->nullable()->unique();
            $table->unsignedInteger('max_agents');
            $table->unsignedInteger('max_monthly_messages');
            $table->unsignedInteger('max_sites')->nullable();   // null = sin tope
            $table->jsonb('features')->default('{}');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status', 16);                       // trialing | active | past_due | unpaid | canceled | incomplete
            $table->string('source', 16)->default('stripe');    // stripe | manual (cortesía del superadmin) | trial
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('stripe_synced_at')->nullable();  // `created` del último evento aplicado: descarta los que llegan desordenados
            $table->timestamp('quota_alert_80_at')->nullable();
            $table->timestamp('quota_alert_100_at')->nullable();
            $table->timestamps();
        });

        // Cada llamada a un modelo de IA: sirve para el consumo del plan (mensajes) y para el costo real.
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);                         // chat | embedding
            $table->string('source', 16);                       // widget | test | document
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('messages')->default(0);    // 1 si cuenta contra el cupo mensual del plan
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0); // costo estimado en micro-USD (1 USD = 1.000.000)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();  // quién lo hizo
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();               // idempotencia: Stripe reenvía eventos
            $table->string('type', 80);
            $table->timestamp('processed_at')->useCurrent();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_superadmin')->default(false);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->jsonb('allowed_domains')->default('[]');    // sitios donde puede mostrarse el widget
        });

        $this->seedPlansAndBackfill();
    }

    /** Los tres planes del producto (los precios y topes se ajustan después desde el panel de superadmin). */
    private function seedPlansAndBackfill(): void
    {
        $now = now();
        $plans = [
            ['starter', 'Starter', 'Para empezar con un agente en tu sitio web.', 1900, 1, 1000, 1, ['human_inbox' => false, 'widget_customization' => false], 1],
            ['business', 'Business', 'Más agentes, atención humana y personalización.', 4900, 3, 5000, 3, ['human_inbox' => true, 'widget_customization' => true], 2],
            ['pro', 'Pro', 'Para equipos con mucho volumen.', 9900, 10, 20000, 10, ['human_inbox' => true, 'widget_customization' => true], 3],
        ];

        foreach ($plans as [$slug, $name, $description, $price, $agents, $messages, $sites, $features, $order]) {
            DB::table('plans')->insert([
                'slug' => $slug, 'name' => $name, 'description' => $description, 'price_cents' => $price,
                'max_agents' => $agents, 'max_monthly_messages' => $messages, 'max_sites' => $sites,
                'features' => json_encode($features), 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Las empresas que ya existen empiezan una prueba, igual que las nuevas.
        $starter = DB::table('plans')->where('slug', 'starter')->value('id');
        $trialDays = (int) config('billing.trial_days', 14);

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            DB::table('subscriptions')->insert([
                'organization_id' => $organizationId, 'plan_id' => $starter, 'status' => 'trialing', 'source' => 'trial',
                'trial_ends_at' => $now->copy()->addDays($trialDays), 'current_period_start' => $now,
                'current_period_end' => $now->copy()->addDays($trialDays), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $table) => $table->dropColumn('allowed_domains'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_superadmin'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'suspension_reason']));
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
