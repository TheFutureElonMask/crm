<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagerTimerEvent extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'happened_at',
    ];

    protected $casts = [
        'happened_at' => 'datetime',
    ];
}
