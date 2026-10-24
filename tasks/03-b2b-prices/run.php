<?php
/**
 * Воспроизведение: php run.php <client_id> <sku> [qty]
 * Пример: php run.php K-001 B-101
 * «Текущее время» зафиксировано: 22.10.2026 12:00 (Минск), чтобы результаты были воспроизводимы.
 * Кеш лежит в var/cache.json (это копия кеша с «боевого» сервера на момент обращений).
 */

declare(strict_types=1);

require __DIR__ . '/src/PriceResolver.php';

function readCsv(string $file): array
{
    $fh = fopen($file, 'r');
    $head = fgetcsv($fh, null, ';', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, null, ';', '"', '')) !== false) {
        $rows[] = array_combine($head, $r);
    }

    return $rows;
}

[$_, $clientId, $sku] = $argv + [null, null, null];
$qty = (int) ($argv[3] ?? 1);
if ($clientId === null || $sku === null) {
    fwrite(STDERR, "Использование: php run.php <client_id> <sku> [qty]\n");
    exit(2);
}

$now = new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('Europe/Minsk'));

$prices = [];
foreach (readCsv(__DIR__ . '/data/prices.csv') as $r) {
    $prices[$r['sku']][$r['price_type']] = (float) $r['price'];
}
$clients  = array_column(readCsv(__DIR__ . '/data/clients.csv'), null, 'client_id');
$products = array_column(readCsv(__DIR__ . '/data/products.csv'), null, 'sku');
$rules    = json_decode((string) file_get_contents(__DIR__ . '/data/discount_rules.json'), true)['rules'];

$resolver = new PriceResolver($prices, $clients, $products, $rules, new FileCache(__DIR__ . '/var/cache.json', $now), $now);

printf("Клиент %s (%s), товар %s x%d\n", $clientId, $clients[$clientId]['price_type'], $sku, $qty);
printf("  базовая цена: %.2f\n", $resolver->basePrice($clientId, $sku));
printf("  скидка:       %.2f%%\n", $resolver->discountPercent($clientId, $sku, $qty));
printf("  цена на сайте: %.2f\n", $resolver->price($clientId, $sku, $qty));
