<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceAccount extends Model
{
    protected $fillable = [
        'integration_type_id',
        'marketplace',
        'name',
        'api_token',
        'credentials',
        'external_account_id',
        'is_active',
        'connection_status',
        'status_message',
        'last_synced_at',
        'tested_at',
        'settings',
    ];

    protected $hidden = [
        'api_token',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'tested_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function integrationType(): BelongsTo
    {
        return $this->belongsTo(IntegrationType::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(MarketplaceSyncLog::class);
    }
}
