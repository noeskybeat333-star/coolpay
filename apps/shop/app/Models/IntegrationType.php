<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationType extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'logo',
        'driver_class',
        'credential_schema',
        'capabilities',
        'settings',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'credential_schema' => 'array',
            'capabilities' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MarketplaceAccount::class);
    }

    public function supports(string $capability): bool
    {
        return (bool) data_get(
            $this->capabilities,
            $capability,
            false,
        );
    }
}
