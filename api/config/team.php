<?php

return [

    // Días que una invitación al equipo sigue siendo válida.
    'invitation_days' => (int) env('TEAM_INVITATION_DAYS', 7),

    // Tope de invitaciones (nuevas o reenviadas) por empresa y hora: evita usar la app para enviar correo masivo.
    'invitations_per_hour' => (int) env('TEAM_INVITATIONS_PER_HOUR', 30),
];
