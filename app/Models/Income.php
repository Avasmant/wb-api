<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    protected $table = 'incomes';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'last_change_date' => 'date',
        'date_close' => 'date',
        'quantity' => 'integer',
        'total_price' => 'decimal:2',
        'income_id' => 'integer',
        'nm_id' => 'integer',
    ];
}
