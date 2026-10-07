<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dimensión de los vectores; debe coincidir con config('ai.embeddings.dimensions'). */
    private const DIMENSIONS = 1536;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                  // text | faq | file
            $table->string('title', 200);                // en FAQ: la pregunta
            $table->longText('content')->nullable();     // texto, respuesta de la FAQ o texto extraído del archivo
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 16)->default('pending'); // pending | processing | ready | failed
            $table->text('error')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['knowledge_base_id', 'status']);
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->string('embedding_model', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index('document_id');
            $table->index('knowledge_base_id');
        });

        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector('.self::DIMENSIONS.') NOT NULL');
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('knowledge_bases');
    }
};
