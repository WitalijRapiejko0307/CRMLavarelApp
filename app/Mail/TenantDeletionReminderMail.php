<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantDeletionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $deletionDateLabel
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Напоминание: удаление аккаунта CRM через 7 дней')
            ->view('emails.tenant-deletion-reminder');
    }
}
