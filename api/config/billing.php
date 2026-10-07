<?php

return [

    // Prueba gratuita al crear una empresa: sin tarjeta, con los topes de este plan.
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),
    'trial_plan' => env('BILLING_TRIAL_PLAN', 'starter'),

    // Aviso de «tu prueba termina» con estos días de antelación, y de «terminó» durante la semana siguiente
    // (pasado ese tiempo no se avisa: una empresa abandonada hace meses no debe recibir un correo de golpe).
    'trial_ending_days' => (int) env('BILLING_TRIAL_ENDING_DAYS', 3),
    'trial_ended_notice_days' => 7,

    // Con un cobro fallido Stripe reintenta durante días; mientras tanto el servicio sigue.
    'past_due_keeps_service' => (bool) env('BILLING_PAST_DUE_KEEPS_SERVICE', true),

    // Avisos al propietario cuando el consumo del mes llega a estos porcentajes.
    'quota_alert_thresholds' => [80, 100],

    // Costo mensual de infraestructura (USD) para el cálculo de margen del superadmin.
    'infrastructure_cost_usd' => (float) env('PLATFORM_INFRA_COST_USD', 0),

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
