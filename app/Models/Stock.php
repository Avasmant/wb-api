<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $table = 'stocks';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'last_change_date' => 'date',
        'quantity' => 'integer',
        'is_supply' => 'boolean',
        'is_realization' => 'boolean',
        'quantity_full' => 'integer',
        'in_way_to_client' => 'integer',
        'in_way_from_client' => 'integer',
        'nm_id' => 'integer',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
    ];
}
