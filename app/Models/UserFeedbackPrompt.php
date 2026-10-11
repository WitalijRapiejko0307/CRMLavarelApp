<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedbackPrompt extends Model
{
    protected $fillable = [
        'user_id',
        'stage',
        'snooze_count',
        'snoozed_until',
        'dismissed_at',
        'notified_at',
    ];

    protected $casts = [
        'snoozed_until' => 'datetime',
        'dismissed_at'  => 'datetime',
        'notified_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
