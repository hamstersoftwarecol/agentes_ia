<?php

return [

    // Avisar por correo cuando un cliente pide una persona (a todo el equipo) y cuando llega un lead (a propietario y administradores).
    'notify_by_email' => (bool) env('INBOX_NOTIFY_BY_EMAIL', true),
];
