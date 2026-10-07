<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** «Tu prueba (o plan por cortesía) terminó.» */
class PlanEnded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $organizationName)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->kind === 'trial' ? 'la prueba gratuita' : 'el plan de cortesía';

        return (new MailMessage)
            ->subject("Terminó {$what} de {$this->organizationName}")
            ->greeting('Hola, '.$notifiable->name)
            ->line(ucfirst($what)." de {$this->organizationName} terminó y tus agentes ya no responden a los clientes de tu sitio.")
            ->line('Elige un plan para reactivarlos al instante: nada de lo que cargaste se perdió.')
            ->action('Elegir un plan', config('app.frontend_url').'/billing');
    }
}
