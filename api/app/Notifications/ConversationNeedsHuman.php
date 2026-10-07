<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Notifications\Concerns\RestoresTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConversationNeedsHuman extends Notification implements ShouldQueue
{
    use Queueable, RestoresTenant;

    public function __construct(public Conversation $conversation)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->restoreTenant($this->conversation);
        // Se vuelve a leer con el contexto ya restaurado: la relación serializada pudo restaurarse vacía en el worker.
        $conversation = $this->conversation->load('contact');

        return (new MailMessage)
            ->subject('Un cliente espera que lo atienda una persona')
            ->greeting('Hola, '.$notifiable->name)
            ->line("{$conversation->contact->displayName()} pidió hablar con una persona en el chat de {$conversation->agent_name}.")
            ->line('La IA dejó de responder en esa conversación hasta que alguien de tu equipo la tome.')
            ->action('Abrir la conversación', config('app.frontend_url')."/conversations?c={$conversation->id}");
    }
}
