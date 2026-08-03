<?php

namespace App\Filament\Resources\MarketplacePrices\Tables;

use App\Filament\Resources\MarketplacePrices\MarketplacePriceResource;
use App\Models\ChannelPrice;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Product;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class MarketplacePricesTable
{
    public static function configure(
        Table $table,
    ): Table {
        return $table
            ->columns([
                ImageColumn::make('primary_image_url')
                    ->label('Фото')
                    ->imageSize(56)
                    ->square()
                    ->checkFileExistence(false),

                TextColumn::make('name')
                    ->label('Товар')
                    ->searchable([
                        'name',
                        'sku',
                    ])
                    ->wrap()
                    ->limit(65),

                TextColumn::make('marketplace_slug')
                    ->label('Канал')
                    ->formatStateUsing(
                        fn (
                            mixed $state,
                            ChannelPrice $record,
                        ): HtmlString => self::marketplaceLogo(
                            (string) $state,
                            $record->marketplace_name,
                        )
                    )
                    ->html(),

                TextColumn::make('connection_name')
                    ->label('Подключение')
                    ->placeholder('—'),

                TextColumn::make('base_price')
                    ->label('До скидки')
                    ->money('RUB')
                    ->placeholder('—')
                    ->visible(
                        fn (mixed $livewire): bool => ! $livewire->isEditingPrices
                    ),

                TextInputColumn::make(
                    'base_price_editor'
                )
                    ->label('До скидки')
                    ->getStateUsing(
                        fn (
                            ChannelPrice $record,
                        ): mixed => $record->base_price
                    )
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->suffix('₽')
                    ->rules([
                        'required',
                        'numeric',
                        'min:0',
                        'max:999999999999.99',
                    ])
                    ->visible(
                        fn (mixed $livewire): bool => $livewire->isEditingPrices
                    )
                    ->disabled(
                        fn (
                            ChannelPrice $record,
                            mixed $livewire,
                        ): bool => ! $livewire->isEditingPrices
                            || ! MarketplacePriceResource::canEdit(
                                $record
                            )
                    )
                    ->updateStateUsing(
                        fn (
                            ChannelPrice $record,
                            mixed $state,
                        ): mixed => self::updatePrice(
                            $record,
                            'base_price',
                            $state,
                        )
                    ),

                TextColumn::make('discount_percent')
                    ->label('Скидка')
                    ->suffix('%')
                    ->placeholder('—')
                    ->visible(
                        fn (mixed $livewire): bool => ! $livewire->isEditingPrices
                    ),

                TextInputColumn::make(
                    'discount_percent_editor'
                )
                    ->label('Скидка')
                    ->getStateUsing(
                        fn (
                            ChannelPrice $record,
                        ): mixed => $record->discount_percent
                    )
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->suffix('%')
                    ->rules([
                        'required',
                        'integer',
                        'min:0',
                        'max:100',
                    ])
                    ->visible(
                        fn (mixed $livewire): bool => $livewire->isEditingPrices
                    )
                    ->disabled(
                        fn (
                            ChannelPrice $record,
                            mixed $livewire,
                        ): bool => ! $livewire->isEditingPrices
                            || ! MarketplacePriceResource::canEdit(
                                $record
                            )
                    )
                    ->updateStateUsing(
                        fn (
                            ChannelPrice $record,
                            mixed $state,
                        ): mixed => self::updatePrice(
                            $record,
                            'discount_percent',
                            $state,
                        )
                    ),

                TextColumn::make('sale_price')
                    ->label('После скидки')
                    ->money('RUB')
                    ->weight('bold')
                    ->placeholder('—'),

                TextColumn::make('stock_quantity')
                    ->label('Остаток')
                    ->numeric()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('sales_channel')
                    ->label('Канал продаж')
                    ->options(
                        fn (): array => self::channelOptions()
                    )
                    ->default('storefront')
                    ->selectablePlaceholder(false)
                    ->searchable()
                    ->query(
                        function (
                            Builder $query,
                            array $data,
                        ): Builder {
                            $channel = (string) (
                                $data['value']
                                ?? 'storefront'
                            );

                            $query->where(
                                'channel_key',
                                $channel,
                            );

                            if ($channel !== 'storefront') {
                                $query->where(
                                    'status',
                                    'active',
                                );
                            }

                            return $query;
                        }
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->paginationPageOptions([
                25,
                50,
                100,
            ]);
    }

    private static function channelOptions(): array
    {
        $options = [
            'storefront' => 'Витрина CoolPay',
        ];

        $accounts = MarketplaceAccount::query()
            ->with('integrationType')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($accounts as $account) {
            $marketplace =
                $account->integrationType?->name
                ?? $account->marketplace
                ?? 'Маркетплейс';

            $options['account:'.$account->getKey()] =
                $marketplace.' — '.$account->name;
        }

        return $options;
    }

    private static function updatePrice(
        ChannelPrice $record,
        string $field,
        mixed $state,
    ): mixed {
        $value = $field === 'discount_percent'
            ? max(
                0,
                min(100, (int) $state),
            )
            : round(
                max(0, (float) $state),
                2,
            );

        if ($record->record_type === 'storefront') {
            $source = Product::query()
                ->findOrFail($record->source_id);

            $source->setAttribute(
                $field,
                $value,
            );

            $salePrice = self::calculateSalePrice(
                (float) $source->base_price,
                (int) $source->discount_percent,
            );

            $source->sale_price = $salePrice;
            $source->save();
        } else {
            $source = MarketplaceListing::query()
                ->findOrFail($record->source_id);

            $source->setAttribute(
                $field,
                $value,
            );

            $salePrice = self::calculateSalePrice(
                (float) $source->base_price,
                (int) $source->discount_percent,
            );

            $source->price = $salePrice;
            $source->save();
        }

        $record->setAttribute(
            $field,
            $value,
        );

        $record->sale_price = $salePrice;

        return $value;
    }

    private static function calculateSalePrice(
        float $basePrice,
        int $discountPercent,
    ): float {
        $discountPercent = max(
            0,
            min(100, $discountPercent),
        );

        return round(
            $basePrice
                * (100 - $discountPercent)
                / 100,
            2,
        );
    }

    private static function marketplaceLogo(
        string $slug,
        ?string $name,
    ): HtmlString {
        [$label, $background, $color] = match ($slug) {
            'storefront' => [
                'CP',
                '#020617',
                '#ffffff',
            ],
            'wildberries' => [
                'WB',
                'linear-gradient(135deg, #cb11ab, #7b2cff)',
                '#ffffff',
            ],
            'ozon' => [
                'OZON',
                '#005bff',
                '#ffffff',
            ],
            'yandex-market' => [
                'ЯМ',
                '#ffd600',
                '#111827',
            ],
            'megamarket' => [
                'ММ',
                '#8b5cf6',
                '#ffffff',
            ],
            'mvideo' => [
                'М.В',
                '#ed1c24',
                '#ffffff',
            ],
            'magnit-market' => [
                'ММ',
                '#e30613',
                '#ffffff',
            ],
            default => [
                'МП',
                '#475569',
                '#ffffff',
            ],
        };

        $title = e($name ?: 'Маркетплейс');

        return new HtmlString(
            '<span title="'.$title.'" style="'
            .'display:inline-flex;'
            .'align-items:center;'
            .'justify-content:center;'
            .'min-width:42px;'
            .'height:28px;'
            .'padding:0 8px;'
            .'border-radius:9px;'
            .'font-size:12px;'
            .'font-weight:800;'
            .'letter-spacing:.02em;'
            .'background:'.$background.';'
            .'color:'.$color.';'
            .'">'
            .e($label)
            .'</span>'
        );
    }
}
