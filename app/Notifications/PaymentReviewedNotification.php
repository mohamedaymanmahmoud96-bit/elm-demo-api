<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Payment $payment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $metadata = $this->payment->metadata ?? [];
        $status = $this->payment->status;

        return [
            'title' => $status === 'paid' ? 'تم تأكيد الدفع' : 'تحديث حالة الدفع',
            'body' => $metadata['approval_message']
                ?? ($status === 'paid'
                    ? 'تم تأكيد طلب الدفع وتفعيل اشتراكك.'
                    : 'تم تحديث حالة طلب الدفع.'),
            'payment_id' => $this->payment->id,
            'status' => $status,
        ];
    }
}
