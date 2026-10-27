<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTrialNoticeTest extends TestCase
{
    public function test_login_and_register_share_configured_trial_days(): void
    {
        config(['subscription.trial_days' => 7]);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Login')
                ->where('trialDays', 7));

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('trialDays', 7));
    }
}
