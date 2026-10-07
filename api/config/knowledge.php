<?php

return [

    // Tamaño objetivo de cada fragmento (en caracteres) y solapamiento entre fragmentos contiguos.
    'chunk_size' => 1000,
    'chunk_overlap' => 150,

    // Tope por documento para acotar el costo de embeddings (~1 MB de texto).
    'max_chunks_per_document' => 1000,

    // Textos por llamada al proveedor de embeddings.
    'embedding_batch_size' => 64,

    // Cuántos fragmentos se recuperan para responder y similitud coseno mínima (0–1).
    'search_limit' => 5,
    'min_similarity' => (float) env('KNOWLEDGE_MIN_SIMILARITY', 0.05),

    // Archivos subidos.
    'max_upload_kb' => 10240,
    'allowed_extensions' => ['pdf', 'txt', 'md'],
    'max_text_chars' => 100000,
];
