<?php

namespace App\Mail;

use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FeedbackSnoozeNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var User */
    public $user;

    /** @var string */
    public $tenantName;

    /** @var string */
    public $stage;

    /** @var string */
    public $stageLabel;

    public function __construct(User $user, string $tenantName, string $stage)
    {
        $this->user       = $user;
        $this->tenantName = $tenantName;
        $this->stage      = $stage;
        $this->stageLabel = $stage === UserFeedback::STAGE_ADVANCED ? 'Второй опрос' : 'Первый опрос';
    }

    public function build(): self
    {
        return $this
            ->subject('[CRM отзыв отложен]')
            ->view('emails.feedback-snooze-notice');
    }
}
