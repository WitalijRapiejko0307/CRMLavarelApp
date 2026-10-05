<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var User */
    public $user;

    /** @var string */
    public $subjectLine;

    /** @var string */
    public $messageText;

    /** @var string */
    public $tenantName;

    public function __construct(User $user, string $subjectLine, string $messageText, string $tenantName)
    {
        $this->user         = $user;
        $this->subjectLine  = $subjectLine;
        $this->messageText  = $messageText;
        $this->tenantName   = $tenantName;
    }

    public function build(): self
    {
        return $this
            ->subject('[CRM] ' . $this->subjectLine)
            ->replyTo($this->user->email, $this->user->name)
            ->view('emails.support-message');
    }
}
