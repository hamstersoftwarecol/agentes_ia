<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentTestChatController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConversationActionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardAlertsController;
use App\Http\Controllers\DashboardSummaryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\KnowledgeSearchController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLogoController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Públicas: precios y webhook de Stripe (se autentica por firma, no por sesión).
Route::get('/plans', [PlanController::class, 'index']);
Route::post('/webhooks/stripe', StripeWebhookController::class);

// Invitaciones al equipo: el token del enlace es la credencial (quien aún no tiene cuenta no puede iniciar sesión).
Route::middleware('throttle:invitation-lookup')->group(function () {
    Route::get('/invitations/{token}', [InvitationAcceptanceController::class, 'show']);
    Route::post('/invitations/{token}/register', [InvitationAcceptanceController::class, 'register']);
});

Route::middleware(['auth:sanctum', 'organization'])->group(function () {
    // Estado de la sesión: el frontend lo usa también sin correo verificado.
    Route::get('/user', [UserController::class, 'show']);
    Route::patch('/user/profile', [UserController::class, 'updateProfile']);
    Route::put('/user/password', [UserController::class, 'updatePassword']);

    Route::middleware('verified')->group(function () {
        Route::post('/organization', [OrganizationController::class, 'store']);
        Route::post('/invitations/{token}/accept', [InvitationAcceptanceController::class, 'accept'])->middleware('throttle:invitation-lookup');

        Route::middleware('organization:required')->group(function () {
            Route::get('/organization', [OrganizationController::class, 'show']);
            Route::patch('/organization', [OrganizationController::class, 'update']);
            Route::put('/organization/business-hours', [OrganizationController::class, 'updateBusinessHours']);
            Route::post('/organization/logo', [OrganizationLogoController::class, 'store']);
            Route::delete('/organization/logo', [OrganizationLogoController::class, 'destroy']);

            // Facturación (la gestiona el propietario; todos los miembros la ven)
            Route::get('/billing', [BillingController::class, 'show']);
            Route::post('/billing/checkout', [BillingController::class, 'checkout']);
            Route::post('/billing/portal', [BillingController::class, 'portal']);

            // Equipo: miembros e invitaciones (ver y gestionar según el rol)
            Route::get('/team', [TeamController::class, 'index']);
            Route::patch('/team/members/{member}', [TeamController::class, 'updateMember']);
            Route::delete('/team/members/{member}', [TeamController::class, 'removeMember']);
            Route::middleware('throttle:team-invitations')->group(function () {
                Route::post('/team/invitations', [InvitationController::class, 'store']);
                Route::post('/team/invitations/{invitation}/resend', [InvitationController::class, 'resend']);
            });
            Route::delete('/team/invitations/{invitation}', [InvitationController::class, 'destroy']);

            // Agentes (AI-001 a AI-009)
            Route::apiResource('agents', AgentController::class);
            Route::post('/agents/{agent}/test-chat', [AgentTestChatController::class, 'store'])->middleware('throttle:agent-test');

            // Base de conocimiento (KB-001 a KB-009)
            Route::apiResource('knowledge-bases', KnowledgeBaseController::class);
            Route::post('/knowledge-bases/{knowledge_base}/search', [KnowledgeSearchController::class, 'store']);
            Route::apiResource('knowledge-bases.documents', DocumentController::class)->shallow()->except('update');
            Route::match(['put', 'patch'], '/documents/{document}', [DocumentController::class, 'update']);
            Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess']);

            // Historial de conversaciones (CHAT-004) y resumen del panel
            Route::get('/conversations', [ConversationController::class, 'index']);
            Route::get('/conversations/{conversation:id}', [ConversationController::class, 'show']);
            Route::post('/conversations/{conversation:id}/take', [ConversationActionController::class, 'take']);
            Route::post('/conversations/{conversation:id}/messages', [ConversationActionController::class, 'reply']);
            Route::post('/conversations/{conversation:id}/release', [ConversationActionController::class, 'release']);
            Route::post('/conversations/{conversation:id}/close', [ConversationActionController::class, 'close']);

            // Leads capturados por los agentes
            Route::apiResource('leads', LeadController::class)->except('store');
            Route::get('/dashboard/summary', DashboardSummaryController::class);
            Route::get('/dashboard/alerts', DashboardAlertsController::class);
            Route::get('/analytics', AnalyticsController::class);
        });
    });
});

// Panel de superadministrador: solo el equipo de la plataforma, sin empresa actual (ven todas).
Route::middleware(['auth:sanctum', 'verified', 'superadmin'])->prefix('admin')->group(function () {
    Route::get('/overview', Admin\OverviewController::class);
    Route::get('/usage', Admin\UsageController::class);
    Route::get('/organizations', [Admin\OrganizationController::class, 'index']);
    Route::get('/organizations/{organization}', [Admin\OrganizationController::class, 'show']);
    Route::post('/organizations/{organization}/plan', [Admin\OrganizationController::class, 'grantPlan']);
    Route::post('/organizations/{organization}/extend-trial', [Admin\OrganizationController::class, 'extendTrial']);
    Route::post('/organizations/{organization}/suspend', [Admin\OrganizationController::class, 'suspend']);
    Route::post('/organizations/{organization}/unsuspend', [Admin\OrganizationController::class, 'unsuspend']);
    Route::get('/plans', [Admin\PlanController::class, 'index']);
    Route::patch('/plans/{plan}', [Admin\PlanController::class, 'update']);
    Route::get('/users', [Admin\UserController::class, 'index']);
    Route::get('/audit-logs', [Admin\AuditLogController::class, 'index']);
    Route::get('/settings', Admin\SettingsController::class);
});
