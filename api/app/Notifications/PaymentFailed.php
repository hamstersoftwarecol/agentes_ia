<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationName)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('No pudimos cobrar tu suscripción')
            ->greeting('Hola, '.$notifiable->name)
            ->line("El último cobro de la suscripción de {$this->organizationName} no se pudo procesar. Reintentaremos automáticamente, pero conviene actualizar tu método de pago para no perder el servicio.")
            ->action('Actualizar método de pago', config('app.frontend_url').'/billing');
    }
}
