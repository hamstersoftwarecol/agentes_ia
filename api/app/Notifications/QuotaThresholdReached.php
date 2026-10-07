<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuotaThresholdReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $percent, public string $planName, public int $used, public int $limit, public string $renewsOn)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $exhausted = $this->percent >= 100;

        return (new MailMessage)
            ->subject($exhausted ? 'Se agotaron los mensajes de tu plan' : "Ya usaste el {$this->percent}% de los mensajes de tu plan")
            ->greeting('Hola, '.$notifiable->name)
            ->line("Tus agentes respondieron {$this->used} de {$this->limit} mensajes incluidos en el plan {$this->planName} este período.")
            ->line($exhausted
                ? "Hasta el {$this->renewsOn} tus agentes no podrán responder a nuevos mensajes. Sube de plan para reactivarlos ahora."
                : "Al llegar al límite tus agentes dejan de responder hasta el {$this->renewsOn}. Sube de plan si prevés más conversaciones.")
            ->action('Ver mi plan', config('app.frontend_url').'/billing');
    }
}
