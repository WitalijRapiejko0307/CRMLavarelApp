<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityDay;
use App\Models\UserFeedback;
use App\Models\UserFeedbackPrompt;
use Carbon\Carbon;

class FeedbackPromptService
{
    public const MIN_ACTIVITY_DAYS = 5;

    public const ADVANCED_DELAY_DAYS = 30;

    public function resolvePromptForUser(?User $user): ?string
    {
        if (!$user || !$user->isTenantUser() || $user->email_verified_at === null) {
            return null;
        }

        $activityDays = UserActivityDay::query()
            ->where('user_id', $user->id)
            ->distinct()
            ->count('activity_date');

        if ($activityDays < self::MIN_ACTIVITY_DAYS) {
            return null;
        }

        if ($this->isStagePromptActive($user, UserFeedback::STAGE_EARLY)) {
            return UserFeedback::STAGE_EARLY;
        }

        if ($this->isAdvancedEligible($user) && $this->isStagePromptActive($user, UserFeedback::STAGE_ADVANCED)) {
            return UserFeedback::STAGE_ADVANCED;
        }

        return null;
    }

    public function countActivityDays(User $user): int
    {
        return (int) UserActivityDay::query()
            ->where('user_id', $user->id)
            ->distinct()
            ->count('activity_date');
    }

    protected function isAdvancedEligible(User $user): bool
    {
        $early = UserFeedback::query()
            ->where('user_id', $user->id)
            ->where('stage', UserFeedback::STAGE_EARLY)
            ->first();

        if (!$early) {
            return false;
        }

        $threshold = Carbon::parse($early->created_at)->addDays(self::ADVANCED_DELAY_DAYS);

        return now()->greaterThanOrEqualTo($threshold);
    }

    protected function isStagePromptActive(User $user, string $stage): bool
    {
        if (UserFeedback::query()
            ->where('user_id', $user->id)
            ->where('stage', $stage)
            ->exists()) {
            return false;
        }

        $prompt = UserFeedbackPrompt::query()
            ->where('user_id', $user->id)
            ->where('stage', $stage)
            ->first();

        if ($prompt && $prompt->dismissed_at !== null) {
            return false;
        }

        if ($prompt && (int) $prompt->snooze_count >= 3) {
            return false;
        }

        if ($prompt && $prompt->snoozed_until !== null && $prompt->snoozed_until->isFuture()) {
            return false;
        }

        return true;
    }

    public function getOrCreatePrompt(User $user, string $stage): UserFeedbackPrompt
    {
        return UserFeedbackPrompt::firstOrCreate(
            ['user_id' => $user->id, 'stage' => $stage],
            ['snooze_count' => 0]
        );
    }
}
