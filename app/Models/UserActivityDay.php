<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivityDay extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'activity_date',
        'created_at',
    ];

    protected $casts = [
        'activity_date' => 'date',
        'created_at'    => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
