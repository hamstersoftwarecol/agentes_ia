<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlanResource;
use App\Models\Plan;

/** Planes públicos (para la página de precios, sin sesión). */
class PlanController extends Controller
{
    public function index()
    {
        return PlanResource::collection(Plan::where('is_active', true)->orderBy('sort_order')->get());
    }
}
