<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceAccount extends Model
{
    protected $fillable = [
        'marketplace',
        'name',
        'api_token',
        'external_account_id',
        'is_active',
        'last_synced_at',
        'settings',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(MarketplaceSyncLog::class);
    }
}
