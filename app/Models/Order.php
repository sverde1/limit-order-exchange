<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    public const SIDE_BUY = 'buy';
    public const SIDE_SELL = 'sell';

    public const STATUS_OPEN = 1;
    public const STATUS_FILLED = 2;
    public const STATUS_CANCELED = 3;

    protected $fillable = [
        'user_id',
        'symbol',
        'side',
        'price',
        'amount',
        'status',
        'opened_at',
        'filled_at',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'price'       => 'decimal:18',
            'amount'      => 'decimal:18',
            'opened_at'   => 'datetime',
            'filled_at'   => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
