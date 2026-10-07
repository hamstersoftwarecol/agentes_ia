<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Concerns\RestoresTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLeadCaptured extends Notification implements ShouldQueue
{
    use Queueable, RestoresTenant;

    public function __construct(public Lead $lead)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->restoreTenant($this->lead);
        $lead = $this->lead;

        $mail = (new MailMessage)
            ->subject('Nuevo lead: '.($lead->name ?: 'cliente sin nombre'))
            ->greeting('Hola, '.$notifiable->name)
            ->line('Tu agente captó un nuevo prospecto en el chat.');

        foreach (['Nombre' => $lead->name, 'Teléfono' => $lead->phone, 'Correo' => $lead->email, 'Interés' => $lead->interest] as $label => $value) {
            if ($value) {
                $mail->line("**{$label}:** {$value}");
            }
        }

        return $mail->action('Ver el lead', config('app.frontend_url').'/leads');
    }
}
