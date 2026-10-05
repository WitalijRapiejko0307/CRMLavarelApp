<?php

namespace App\Services;

use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailVerificationCodeService
{
    public const CODE_TTL_MINUTES = 15;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public function sendCode(User $user): void
    {
        $latest = EmailVerificationCode::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if ($latest && $latest->created_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw ValidationException::withMessages([
                'code' => 'Повторная отправка возможна через минуту. Подождите немного.',
            ]);
        }

        EmailVerificationCode::where('user_id', $user->id)->delete();

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'user_id'    => $user->id,
            'code_hash'  => Hash::make($plainCode),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        Mail::to($user->email)->send(new EmailVerificationCodeMail($plainCode));
    }

    public function ensureCodeSent(User $user): void
    {
        if ($this->hasActiveCode($user)) {
            return;
        }

        try {
            $this->sendCode($user);
        } catch (ValidationException $e) {
            // Login path: do not block sign-in if resend is in cooldown.
        }
    }

    public function hasActiveCode(User $user): bool
    {
        return EmailVerificationCode::where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function verify(User $user, string $code): void
    {
        $record = EmailVerificationCode::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$record || $record->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'Код истёк. Запросите новый.',
            ]);
        }

        if (!Hash::check($code, $record->code_hash)) {
            throw ValidationException::withMessages([
                'code' => 'Неверный код. Проверьте письмо и попробуйте снова.',
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        EmailVerificationCode::where('user_id', $user->id)->delete();
    }
}
