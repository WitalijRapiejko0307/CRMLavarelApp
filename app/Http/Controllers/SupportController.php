<?php

namespace App\Http\Controllers;

use App\Mail\SupportMessageMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
    }

    public function index(): Response
    {
        $username = (string) config('support.telegram_username');

        return Inertia::render('Support/Index', [
            'telegram_url' => 'https://t.me/' . ltrim($username, '@'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $user   = Auth::user()->loadMissing('tenant');
        $tenant = $user->tenant;
        $to     = (string) config('support.support_email');

        try {
            Mail::to($to)
                ->cc($user->email)
                ->send(new SupportMessageMail(
                    $user,
                    $data['subject'],
                    $data['message'],
                    $tenant ? (string) $tenant->name : '—'
                ));
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', 'Не удалось отправить сообщение. Попробуйте позже или напишите в Telegram.');
        }

        return back()->with('message', 'Сообщение отправлено.');
    }
}
