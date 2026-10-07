<?php

use App\Http\Controllers\Widget\WidgetController;
use App\Http\Controllers\Widget\WidgetMessageController;
use Illuminate\Support\Facades\Route;

// API pública del widget. Prefijo /api/widget; sin sesión, sin CSRF, CORS abierto (config/cors.php).
Route::get('{widget_agent}/config', [WidgetController::class, 'config'])
    ->middleware('throttle:widget-config');

Route::post('{widget_agent}/messages', [WidgetMessageController::class, 'store'])
    ->middleware('throttle:widget-messages');

Route::get('{widget_agent}/conversations/{conversation:public_id}/messages', [WidgetMessageController::class, 'index'])
    ->whereUuid('conversation')
    ->middleware('throttle:widget-poll');
