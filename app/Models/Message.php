<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['lead_id', 'text', 'external_id', 'direction', 'status'];

    public function lead() {
        return $this->belongsTo(Lead::class);
    }
}
