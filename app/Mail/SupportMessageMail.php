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

    /** @var string */
    public $replyEmail;

    public function __construct(User $user, string $subjectLine, string $messageText, string $tenantName, string $replyEmail)
    {
        $this->user         = $user;
        $this->subjectLine  = $subjectLine;
        $this->messageText  = $messageText;
        $this->tenantName   = $tenantName;
        $this->replyEmail   = $replyEmail;
    }

    public function build(): self
    {
        return $this
            ->subject('[CRM] ' . $this->subjectLine)
            ->replyTo($this->replyEmail, $this->user->name)
            ->view('emails.support-message');
    }
}
