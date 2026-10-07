<?php

namespace App\Contracts;

use App\Services\AI\EmbeddingResponse;

interface EmbeddingProvider
{
    /** Identificador del modelo; se guarda con cada fragmento y la búsqueda solo compara vectores del mismo modelo. */
    public function model(): string;

    /**
     * @param  list<string>  $texts
     * @return EmbeddingResponse Un vector por texto, en el mismo orden.
     */
    public function embed(array $texts): EmbeddingResponse;
}
