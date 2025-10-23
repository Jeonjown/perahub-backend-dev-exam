<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLog extends Model
{
    protected $fillable = [
        'type',
        'endpoint',
        'request_body',
        'response_body',
    ];

    protected $casts = [
        'request_body' => 'array',
        'response_body' => 'array',
    ];
}
