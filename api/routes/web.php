<?php

use Illuminate\Support\Facades\Route;

// Todas las rutas de autenticación (Breeze) viven bajo /api para que el
// frontend solo tenga que hacer proxy de un prefijo. Usan sesión por cookie.
Route::prefix('api')->group(__DIR__.'/auth.php');
