<?php

return [

    // Largo máximo de un mensaje del visitante (caracteres).
    'max_message_length' => 1000,

    // Mensajes del historial que se envían al modelo en cada turno (los más recientes).
    'history_messages' => 20,

    // Tope de mensajes (visitante + agente) por conversación; frena abusos y gasto descontrolado.
    'max_messages_per_conversation' => 100,
];
