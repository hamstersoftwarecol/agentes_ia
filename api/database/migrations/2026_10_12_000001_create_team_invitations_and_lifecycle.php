<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Usuarios incluidos por plan (se ajustan después desde /admin/plans). */
    private const SEATS = ['starter' => 1, 'business' => 3, 'pro' => 10];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_users')->default(1)->after('max_sites');
        });

        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode($plan->features ?? '{}', true) ?: [];
            $features['analytics'] = in_array($plan->slug, ['business', 'pro'], true);

            DB::table('plans')->where('id', $plan->id)->update([
                'max_users' => self::SEATS[$plan->slug] ?? 1,
                'features' => json_encode($features),
            ]);
        }

        // Una invitación pendiente por correo y empresa; al aceptarla se borra (queda constancia en audit_logs).
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');                              // en minúsculas
            $table->string('role', 16);                           // admin | agent
            $table->string('token_hash', 64)->unique();           // sha256: el token solo existe en el correo y en el enlace
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
        });

        // Analíticas: `handoff_reason`/`handoff_at` se borran al devolver la conversación a la IA, así que se guarda
        // aparte, una sola vez, el momento en que alguien pidió una persona (y un índice para filtrar por fecha).
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('handoff_requested_at')->nullable()->after('handoff_at');
            $table->index(['organization_id', 'created_at']);
        });
        DB::table('conversations')->whereNotNull('handoff_reason')->update(['handoff_requested_at' => DB::raw('handoff_at')]);

        // Avisos del ciclo de vida: una sola vez por prueba (se limpian si la prueba se extiende).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('ending_notified_at')->nullable();
            $table->timestamp('ended_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn('handoff_requested_at');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['ending_notified_at', 'ended_notified_at']);
        });
        Schema::dropIfExists('organization_invitations');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_users');
        });
    }
};
