<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceSyncLog extends Model
{
    protected $fillable = [
        'marketplace_account_id',
        'operation',
        'status',
        'received_count',
        'created_count',
        'updated_count',
        'failed_count',
        'message',
        'details',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            MarketplaceAccount::class,
            'marketplace_account_id'
        );
    }
}
