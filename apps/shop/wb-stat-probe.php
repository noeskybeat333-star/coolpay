<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$account = \App\Models\MarketplaceAccount::query()
    ->where('is_active', true)
    ->first();

$token = data_get($account->credentials, 'api_token');

$response = \Illuminate\Support\Facades\Http::acceptJson()
    ->withToken($token)
    ->connectTimeout(10)
    ->timeout(120)
    ->get('https://statistics-api.wildberries.ru/api/v1/supplier/orders', [
        'dateFrom' => \Carbon\CarbonImmutable::now()
            ->subDays(400)
            ->format('Y-m-d\TH:i:s'),
        'flag' => 0,
    ]);

echo 'HTTP '.$response->status()."\n";

$body = $response->json();

echo 'Записей: '.(is_array($body) ? count($body) : '—')."\n\n";

if (is_array($body) && $body !== []) {
    echo json_encode(
        $body[0],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )."\n";
} else {
    echo mb_substr($response->body(), 0, 300)."\n";
}
