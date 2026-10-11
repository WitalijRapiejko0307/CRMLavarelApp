<?php

namespace Tests\Feature;

use App\Mail\FeedbackResponseMail;
use App\Mail\FeedbackSnoozeNoticeMail;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Models\UserFeedback;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FeedbackSurveyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createVerifiedUser(?string $email = null): User
    {
        $tenant = Tenant::create([
            'name'                => 'Feedback Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Feedback User',
            'email'     => $email ?? 'feedback-user@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function createUnverifiedUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Unverified Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id'         => $tenant->id,
            'name'              => 'Unverified User',
            'email'             => 'unverified@example.com',
            'password'          => Hash::make('password'),
            'role'              => 'admin',
            'email_verified_at' => null,
        ]);
    }

    private function inertiaGet(User $user, string $url)
    {
        $headers = [
            'X-Inertia'        => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        $manifest = public_path('mix-manifest.json');
        if (is_file($manifest)) {
            $headers['X-Inertia-Version'] = md5_file($manifest);
        }

        return $this->actingAs($user)->get($url, $headers);
    }

    /**
     * @param int $count
     */
    private function seedActivityDays(User $user, $count, ?Carbon $endOn = null): void
    {
        $end = $endOn ?? Carbon::now(config('app.timezone'));

        for ($i = 0; $i < $count; $i++) {
            UserActivityDay::create([
                'user_id'       => $user->id,
                'activity_date' => $end->copy()->subDays($count - 1 - $i)->toDateString(),
                'created_at'    => now(),
            ]);
        }
    }

    private function validEarlyPayload(): array
    {
        return [
            'stage'        => 'early',
            'orders_score' => 4,
            'postal_score' => 5,
            'missing'      => 'speed',
        ];
    }

    public function test_two_gets_same_day_create_one_activity_row(): void
    {
        $user = $this->createVerifiedUser();
        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00', config('app.timezone')));

        $this->actingAs($user)->get('/support')->assertOk();
        $this->actingAs($user)->get('/support')->assertOk();

        $this->assertSame(1, UserActivityDay::where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    public function test_next_calendar_day_creates_second_activity_row(): void
    {
        $user = $this->createVerifiedUser();

        Carbon::setTestNow(Carbon::parse('2026-10-11 10:00:00', config('app.timezone')));
        $this->actingAs($user)->get('/support')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone')));
        $this->actingAs($user)->get('/support')->assertOk();

        $this->assertSame(2, UserActivityDay::where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    public function test_guest_does_not_create_activity_row(): void
    {
        $this->get('/support')->assertRedirect('/login');
        $this->assertSame(0, UserActivityDay::count());
    }

    public function test_four_activity_days_no_feedback_prompt(): void
    {
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 4);

        $this->inertiaGet($user, '/support')
            ->assertOk()
            ->assertJsonPath('props.feedback_prompt', null);
    }

    public function test_five_activity_days_shows_early_prompt(): void
    {
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        $this->inertiaGet($user, '/support')
            ->assertOk()
            ->assertJsonPath('props.feedback_prompt', 'early');
    }

    public function test_later_hides_prompt_until_snooze_expires(): void
    {
        Mail::fake();
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00', config('app.timezone')));

        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', 'early');

        $this->actingAs($user)->post('/feedback/later')->assertRedirect();

        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', null);

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', config('app.timezone')));
        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', null);

        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', config('app.timezone')));
        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', 'early');

        $this->actingAs($user)->post('/feedback/later')->assertRedirect();
        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', null);

        Carbon::setTestNow();
    }

    public function test_third_later_sends_snooze_notice_once_and_hides_prompt(): void
    {
        Mail::fake();
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', config('app.timezone')));

        $this->actingAs($user)->post('/feedback/later')->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', config('app.timezone')));
        $this->actingAs($user)->post('/feedback/later')->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', config('app.timezone')));
        $this->actingAs($user)->post('/feedback/later')->assertRedirect();

        Mail::assertSent(FeedbackSnoozeNoticeMail::class, 1);

        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', null);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', config('app.timezone')));
        $this->actingAs($user)->post('/feedback/later')->assertForbidden();
        Mail::assertSent(FeedbackSnoozeNoticeMail::class, 1);

        Carbon::setTestNow();
    }

    public function test_advanced_prompt_hidden_until_thirty_days_after_early_feedback(): void
    {
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', config('app.timezone')));

        UserFeedback::create([
            'user_id'    => $user->id,
            'stage'      => 'early',
            'answers'    => $this->validEarlyPayload(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', config('app.timezone')));
        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', null);

        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', config('app.timezone')));
        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', 'advanced');

        Carbon::setTestNow();
    }

    public function test_guest_post_redirects_to_login(): void
    {
        $this->post('/feedback', $this->validEarlyPayload())->assertRedirect('/login');
        $this->post('/feedback/later')->assertRedirect('/login');
    }

    public function test_unverified_user_post_returns_forbidden(): void
    {
        $user = $this->createUnverifiedUser();
        $this->seedActivityDays($user, 5);

        $this->actingAs($user)
            ->post('/feedback', $this->validEarlyPayload())
            ->assertForbidden();
    }

    public function test_user_with_four_days_post_returns_forbidden(): void
    {
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 4);

        $this->actingAs($user)
            ->post('/feedback', $this->validEarlyPayload())
            ->assertForbidden();
    }

    public function test_valid_early_post_sends_mail_and_clears_prompt(): void
    {
        Mail::fake();
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        $this->actingAs($user)
            ->post('/feedback', $this->validEarlyPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Mail::assertSent(FeedbackResponseMail::class, 1);
        $this->assertDatabaseHas('user_feedbacks', [
            'user_id' => $user->id,
            'stage'   => 'early',
        ]);

        $this->inertiaGet($user, '/support')->assertJsonPath('props.feedback_prompt', null);
    }

    public function test_missing_required_score_returns_validation_error(): void
    {
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        $payload = $this->validEarlyPayload();
        unset($payload['orders_score']);

        $this->actingAs($user)
            ->from('/support')
            ->post('/feedback', $payload)
            ->assertSessionHasErrors('orders_score');
    }

    public function test_duplicate_stage_feedback_returns_unprocessable(): void
    {
        Mail::fake();
        $user = $this->createVerifiedUser();
        $this->seedActivityDays($user, 5);

        $this->actingAs($user)
            ->post('/feedback', $this->validEarlyPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->from('/support')
            ->post('/feedback', $this->validEarlyPayload())
            ->assertRedirect('/support')
            ->assertSessionHasErrors('stage');
    }
}
