<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory; 
class Lead extends Model
{
    use HasFactory; 
    protected $fillable = [
        'user_id', 'client_name', 'phone', 'email', 
        'title', 'description', 'price', 'status', 
        'source', 'green_api_instance_id', 'next_action_at', 'chat_step'
    ];

    protected $casts = [
        'next_action_at' => 'datetime',
        'price' => 'float'
    ];
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function messages() {
        return $this->hasMany(\App\Models\Message::class);
    }
    public function activities()
    {
        return $this->hasMany(Activity::class)->orderBy('created_at', 'desc');
    }
    public function setNextAction(string $timeFrame)
    {
        $date = match($timeFrame) {
            'tomorrow' => now()->addDay(),
            '3days'    => now()->addDays(3),
            'week'     => now()->addWeek(),
            default    => now(),
        };

        $this->update(['next_action_at' => $date]);
    }
}
