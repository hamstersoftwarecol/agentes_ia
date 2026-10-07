<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // Un lead por conversación: los datos que el cliente va dando se fusionan en el mismo registro.
            $table->foreignId('conversation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('interest', 500)->nullable();
            $table->string('status', 16)->default('new'); // new | contacted | qualified | converted | lost
            $table->text('notes')->nullable();
            $table->string('source', 16)->default('ai');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('contact_id')->constrained('users')->nullOnDelete();
            $table->string('handoff_reason', 16)->nullable()->after('status'); // visitor | ai
            $table->timestamp('handoff_at')->nullable()->after('handoff_reason');
            $table->timestamp('closed_at')->nullable()->after('handoff_at');

            $table->index(['organization_id', 'status']);
        });

        Schema::table('messages', function (Blueprint $table) {
            // Mensajes escritos por una persona del equipo (role = human).
            $table->foreignId('user_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->string('sender_name', 80)->nullable()->after('user_id'); // lo que ve el visitante
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('sender_name');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['handoff_reason', 'handoff_at', 'closed_at']);
        });
        Schema::dropIfExists('leads');
    }
};
