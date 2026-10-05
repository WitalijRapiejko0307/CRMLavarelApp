<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->withMiddleware(\App\Http\Middleware\EnsureEmailVerified::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registerUnverifiedUser(): User
    {
        Mail::fake();

        $this->post('/register', [
            'company_name'          => 'Verify Shop',
            'tenant_type'           => 'store',
            'name'                  => 'Owner',
            'email'                 => 'verify-owner@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('email.verify.show'));

        $user = User::where('email', 'verify-owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) {
            return strlen($mail->code) === 6;
        });

        return $user;
    }

    private function plainCodeFromLastMail(): string
    {
        $sent = null;
        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use (&$sent) {
            $sent = $mail->code;

            return true;
        });

        return (string) $sent;
    }

    public function test_register_sends_mail_and_blocks_settings_until_verified(): void
    {
        $user = $this->registerUnverifiedUser();

        $this->actingAs($user)
            ->get('/settings')
            ->assertRedirect(route('email.verify.show'));
    }

    public function test_correct_code_verifies_email_and_allows_app(): void
    {
        $user = $this->registerUnverifiedUser();
        $code = $this->plainCodeFromLastMail();

        $this->actingAs($user)
            ->post('/email/verify', ['code' => $code])
            ->assertRedirect('/settings');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/settings')
            ->assertOk();
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = $this->registerUnverifiedUser();

        $this->actingAs($user)
            ->from(route('email.verify.show'))
            ->post('/email/verify', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->registerUnverifiedUser();
        $code = $this->plainCodeFromLastMail();

        EmailVerificationCode::where('user_id', $user->id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->from(route('email.verify.show'))
            ->post('/email/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_resend_is_throttled_within_sixty_seconds(): void
    {
        $user = $this->registerUnverifiedUser();

        $this->actingAs($user)
            ->from(route('email.verify.show'))
            ->post('/email/verify/resend')
            ->assertSessionHasErrors('code');
    }

    public function test_existing_verified_user_can_open_normal_page(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Verified Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id'         => $tenant->id,
            'name'              => 'Verified',
            'email'             => 'verified@example.com',
            'password'          => Hash::make('password'),
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/orders')
            ->assertOk();
    }

    public function test_login_with_unverified_email_redirects_to_verify_and_sends_code(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                => 'Login Verify',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Unverified',
            'email'     => 'unverified-login@example.com',
            'password'  => Hash::make('password123'),
            'role'      => 'admin',
        ]);

        $this->post('/login', [
            'email'    => 'unverified-login@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('email.verify.show'));

        Mail::assertSent(EmailVerificationCodeMail::class);
    }
}
