<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedback extends Model
{
    protected $table = 'user_feedbacks';

    public const STAGE_EARLY = 'early';

    public const STAGE_ADVANCED = 'advanced';

    public const STAGES = [
        self::STAGE_EARLY,
        self::STAGE_ADVANCED,
    ];

    protected $fillable = [
        'user_id',
        'stage',
        'answers',
    ];

    protected $casts = [
        'answers' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
