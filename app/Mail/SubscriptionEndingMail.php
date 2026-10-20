<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionEndingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $endsAtLabel
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Подписка CRM закончится через 5 дней')
            ->view('emails.subscription-ending');
    }
}
