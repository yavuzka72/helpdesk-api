<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlaSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'priority',
        'response_minutes',
        'resolution_minutes',
    ];
}
