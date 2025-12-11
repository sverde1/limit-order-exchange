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
        'created_at',
        'updated_at',
    ];

    /**
     * Attribute casts for order fields.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price'       => 'decimal:8',
            'amount'      => 'decimal:18',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    /**
     * Owning user.
     *
     * @return BelongsTo<Order, User>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
