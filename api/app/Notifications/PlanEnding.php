<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** «Tu prueba (o plan por cortesía) termina pronto.» Solo datos simples: se serializa a la cola sin modelos. */
class PlanEnding extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $organizationName, public int $daysLeft, public string $endsOn)
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
        $days = $this->daysLeft === 1 ? 'mañana' : "en {$this->daysLeft} días";

        return (new MailMessage)
            ->subject(ucfirst($what)." de {$this->organizationName} termina {$days}")
            ->greeting('Hola, '.$notifiable->name)
            ->line(ucfirst($what)." de {$this->organizationName} termina el {$this->endsOn}.")
            ->line('Después de esa fecha tus agentes dejan de responder a los clientes que escriben desde tu sitio. Tu información y tus conversaciones se conservan.')
            ->action('Elegir un plan', config('app.frontend_url').'/billing');
    }
}
