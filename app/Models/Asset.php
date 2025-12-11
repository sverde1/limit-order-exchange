<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\AssetFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * The assets table does not store created_at/updated_at columns.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'symbol',
        'amount',
        'locked_amount',
    ];

    /**
     * Attribute casts for asset amounts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:18',
            'locked_amount' => 'decimal:18',
        ];
    }

    /**
     * Owning user.
     *
     * @return BelongsTo<Asset, User>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
