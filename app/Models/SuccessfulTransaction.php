<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuccessfulTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'request_body',
        'response_body',
    ];
}
