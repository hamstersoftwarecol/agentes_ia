<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();
            $table->string('public_id', 24)->unique();   // ag_XXXXXXXXXX: lo que irá en el widget
            $table->string('name', 80);
            $table->string('role', 120)->nullable();
            $table->text('goal')->nullable();
            $table->string('tone', 32)->default('friendly_professional');
            $table->string('language', 5)->default('es');
            $table->text('instructions')->nullable();
            $table->text('welcome_message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
