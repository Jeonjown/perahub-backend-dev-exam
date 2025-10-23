<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'request_body',
        'error_message',
        'status',
    ];
}
