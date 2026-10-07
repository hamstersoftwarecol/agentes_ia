<?php

namespace App\Services\Inbox;

use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;

class LeadCapture
{
    /**
     * Crea el lead de la conversación o fusiona los datos nuevos en el existente (el agente repite en cada
     * turno lo que ya recopiló y el cliente puede corregir un dato). El estado y las notas del lead no se tocan.
     *
     * @param  array{name: ?string, phone: ?string, email: ?string, interest: ?string}  $data  Ya normalizados.
     * @return array{0: Lead, 1: bool} El lead y si se acaba de crear.
     */
    public function capture(Conversation $conversation, Contact $contact, ?Agent $agent, array $data): array
    {
        $lead = Lead::firstOrNew(['conversation_id' => $conversation->id]);
        $created = ! $lead->exists;

        if ($created) {
            $lead->forceFill(['contact_id' => $contact->id, 'agent_id' => $agent?->id]);
        }

        // Solo se pisa con datos presentes: un `null` nuevo no borra lo que ya se sabía.
        $lead->forceFill(array_filter($data, fn ($value) => $value !== null))->save();

        // El contacto pasa de "Visitante #12" a tener nombre y datos reales.
        $contact->forceFill(array_filter([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
        ], fn ($value) => $value !== null))->save();

        return [$lead, $created];
    }
}
