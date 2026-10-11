<?php

namespace App\Http\Middleware;

use App\Models\UserActivityDay;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class RecordUserActivityDay
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET')) {
            $user = $request->user();

            if ($user) {
                $today = Carbon::now(config('app.timezone'))->toDateString();

                if ($request->session()->get('activity_day') !== $today) {
                    $exists = UserActivityDay::query()
                        ->where('user_id', $user->id)
                        ->whereDate('activity_date', $today)
                        ->exists();

                    if (!$exists) {
                        UserActivityDay::create([
                            'user_id'       => $user->id,
                            'activity_date' => $today,
                            'created_at'    => now(),
                        ]);
                    }

                    $request->session()->put('activity_day', $today);
                }
            }
        }

        return $next($request);
    }
}
