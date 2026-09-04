<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mailing extends Model
{
    protected $fillable = ['subject', 'message', 'status', 'total_count', 'sent_count'];
}
