<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FeedbackResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var User */
    public $user;

    /** @var string */
    public $tenantName;

    /** @var string */
    public $stage;

    /** @var array<string, mixed> */
    public $answers;

    /**
     * @param array<string, mixed> $answers
     */
    public function __construct(User $user, string $tenantName, string $stage, array $answers)
    {
        $this->user       = $user;
        $this->tenantName = $tenantName;
        $this->stage      = $stage;
        $this->answers    = $answers;
    }

    public function build(): self
    {
        return $this
            ->subject('[CRM отзыв]')
            ->replyTo($this->user->email, $this->user->name)
            ->view('emails.feedback-response');
    }
}
