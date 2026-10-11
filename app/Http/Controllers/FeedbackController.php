<?php

namespace App\Http\Controllers;

use App\Mail\FeedbackResponseMail;
use App\Mail\FeedbackSnoozeNoticeMail;
use App\Models\UserFeedback;
use App\Services\FeedbackPromptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeedbackController extends Controller
{
    /** @var FeedbackPromptService */
    protected $promptService;

    public function __construct(FeedbackPromptService $promptService)
    {
        $this->middleware(['auth', 'tenant']);
        $this->promptService = $promptService;
    }

    public function later(Request $request)
    {
        $user = Auth::user()->loadMissing('tenant');

        if ($user->email_verified_at === null) {
            abort(403);
        }

        $activeStage = $this->promptService->resolvePromptForUser($user);

        if ($activeStage === null) {
            abort(403);
        }

        $prompt = $this->promptService->getOrCreatePrompt($user, $activeStage);
        $prompt->snooze_count = (int) $prompt->snooze_count + 1;
        $prompt->snoozed_until = now()->addDays(7);

        if ($prompt->snooze_count >= 3) {
            $prompt->dismissed_at = now();

            if ($prompt->notified_at === null) {
                $to = (string) config('support.support_email');
                $tenantName = $user->tenant ? (string) $user->tenant->name : '—';

                if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    try {
                        Mail::to($to)->send(new FeedbackSnoozeNoticeMail(
                            $user,
                            $tenantName,
                            $activeStage
                        ));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                $prompt->notified_at = now();
            }
        }

        $prompt->save();

        return back();
    }

    public function store(Request $request)
    {
        $user = Auth::user()->loadMissing('tenant');

        if ($user->email_verified_at === null) {
            abort(403);
        }

        if ($this->promptService->countActivityDays($user) < FeedbackPromptService::MIN_ACTIVITY_DAYS) {
            abort(403);
        }

        $data = $request->validate([
            'stage'         => ['required', 'string', Rule::in(UserFeedback::STAGES)],
            'orders_score'  => ['required_if:stage,early', 'nullable', 'integer', 'min:1', 'max:5'],
            'postal_score'  => ['required_if:stage,early', 'nullable', 'integer', 'min:1', 'max:5'],
            'missing'       => ['required_if:stage,early', 'nullable', 'string', Rule::in(['speed', 'blanks', 'stock', 'reports', 'other'])],
            'missing_other' => ['required_if:missing,other', 'nullable', 'string', 'max:200'],
            'improve'       => ['nullable', 'string', 'max:1000'],
            'score'         => ['required_if:stage,advanced', 'nullable', 'integer', 'min:1', 'max:5'],
            'comment'       => ['required_if:stage,advanced', 'nullable', 'string', 'max:5000'],
            'telegram'      => ['nullable', 'string', 'max:64'],
        ]);

        $stage = $data['stage'];

        if (UserFeedback::query()
            ->where('user_id', $user->id)
            ->where('stage', $stage)
            ->exists()) {
            throw ValidationException::withMessages([
                'stage' => ['Отзыв для этого этапа уже отправлен.'],
            ]);
        }

        $activeStage = $this->promptService->resolvePromptForUser($user);

        if ($activeStage !== $stage) {
            abort(403);
        }

        $answers = $this->buildAnswers($stage, $data);
        $tenantName = $user->tenant ? (string) $user->tenant->name : '—';
        $to = (string) config('support.support_email');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()
                ->withInput()
                ->with('error', 'Почта поддержки не настроена.');
        }

        try {
            Mail::to($to)->send(new FeedbackResponseMail(
                $user,
                $tenantName,
                $stage,
                $answers
            ));
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Не удалось отправить отзыв. Попробуйте позже.');
        }

        UserFeedback::create([
            'user_id' => $user->id,
            'stage'   => $stage,
            'answers' => $answers,
        ]);

        return back()->with('message', 'Спасибо за отзыв!');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function buildAnswers(string $stage, array $data): array
    {
        if ($stage === UserFeedback::STAGE_EARLY) {
            $answers = [
                'orders_score' => (int) $data['orders_score'],
                'postal_score' => (int) $data['postal_score'],
                'missing'      => $data['missing'],
            ];

            if ($data['missing'] === 'other') {
                $answers['missing_other'] = $data['missing_other'];
            }

            if (!empty($data['improve'])) {
                $answers['improve'] = $data['improve'];
            }

            return $answers;
        }

        $answers = [
            'score'   => (int) $data['score'],
            'comment' => $data['comment'],
        ];

        if (!empty($data['telegram'])) {
            $answers['telegram'] = $data['telegram'];
        }

        return $answers;
    }
}
