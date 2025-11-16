<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentTokenNotification extends Notification
{
    use Queueable;
    protected string $token;
    protected float $amount;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token, float $amount)
    {
        $this->token = $token;
        $this->amount = $amount;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmación de Pago - Tu Token de Seguridad')
            ->greeting('Hola ' . $notifiable->names . '!')
            ->line('Estás a punto de confirmar un pago desde tu billetera por un monto de ' . number_format($this->amount, 2) . '.')
            ->line('Por favor, usa el siguiente **Token de Seguridad** para completar la transacción:')
            ->line('**' . $this->token . '**')
            ->line('Este token expira en 5 minutos. Si no solicitaste este pago, ignora este correo.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
