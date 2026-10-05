<?php

namespace Tests\Feature;

use App\Mail\SupportMessageMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createVerifiedUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Support Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id'         => $tenant->id,
            'name'              => 'Support User',
            'email'             => 'support-user@example.com',
            'password'          => Hash::make('password'),
            'role'              => 'admin',
            'email_verified_at' => now(),
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/support')->assertRedirect('/login');
    }

    public function test_support_page_includes_telegram_url(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->inertiaGet($user, '/support');

        $response->assertOk();
        $response->assertJsonPath('props.telegram_url', 'https://t.me/vitali_rapeika');
    }

    public function test_verified_user_can_send_support_message(): void
    {
        Mail::fake();
        $user = $this->createVerifiedUser();

        $this->actingAs($user)
            ->post('/support', [
                'subject'     => 'Проблема с заказом',
                'message'     => 'Не открывается карточка заказа.',
                'reply_email' => 'answer@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertSent(SupportMessageMail::class, function (SupportMessageMail $mail) use ($user) {
            return $mail->user->id === $user->id
                && $mail->subjectLine === 'Проблема с заказом'
                && $mail->messageText === 'Не открывается карточка заказа.'
                && $mail->replyEmail === 'answer@example.com';
        });
    }

    public function test_empty_message_returns_validation_error(): void
    {
        $user = $this->createVerifiedUser();

        $this->actingAs($user)
            ->from('/support')
            ->post('/support', [
                'subject'     => 'Тема',
                'reply_email' => 'answer@example.com',
                'message'     => '',
            ])
            ->assertSessionHasErrors('message');
    }
}
