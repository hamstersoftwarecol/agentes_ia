<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Visitante del widget. `visitor_id` es un UUID aleatorio que genera el navegador del
        // visitante y funciona como secreto para recuperar su propio historial.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('visitor_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['organization_id', 'visitor_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();           // lo único que ve el widget
            // Al borrar el agente se conserva el historial (con el nombre que tenía).
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_name', 80);
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('ai');   // ai | human | waiting | closed
            $table->string('channel', 16)->default('web');
            $table->string('page_url', 500)->nullable();   // sin query ni fragmento
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'last_message_at']);
            $table->index(['organization_id', 'agent_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);                    // user | assistant
            $table->text('content');
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->jsonb('sources')->nullable();          // fragmentos de conocimiento usados
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('contacts');
    }
};
