<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $dateLabel,
        public string $bodyText
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Сводка CRM за ' . $this->dateLabel)
            ->view('emails.daily-digest');
    }
}
