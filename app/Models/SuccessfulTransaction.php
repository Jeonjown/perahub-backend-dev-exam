<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuccessfulTransaction extends Model
{
    protected $table = 'success_transactions';

    protected $fillable = [
        'transaction_id',
        'partner_id',
        'request_body',
        'response_body',
    ];

    protected $casts = [
        'request_body' => 'array',
        'response_body' => 'array',
    ];
}
