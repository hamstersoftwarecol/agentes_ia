<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Para paginar filas ya armadas como arreglos (listados del panel de superadmin). */
class ArrayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
