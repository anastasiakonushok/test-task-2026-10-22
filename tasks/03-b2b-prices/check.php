<?php
/**
 * Проверка исправлений. Исходный var/cache.json не меняет.
 * Запуск: php check.php
 *
 * «Сейчас» — текущие дата и время по Минску. Цены, клиенты и товары читаются из data/.
 * Пять обращений из TASK.md — те же клиент, артикул и количество.
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
    fclose($fh);

    return $rows;
}

/** @var array<string, array<string, float>> */
$prices = [];
foreach (readCsv(__DIR__ . '/data/prices.csv') as $r) {
    $prices[$r['sku']][$r['price_type']] = (float) $r['price'];
}
/** @var array<string, array<string, string>> */
$clients = array_column(readCsv(__DIR__ . '/data/clients.csv'), null, 'client_id');
/** @var array<string, array<string, string>> */
$products = array_column(readCsv(__DIR__ . '/data/products.csv'), null, 'sku');
$rules = json_decode((string) file_get_contents(__DIR__ . '/data/discount_rules.json'), true)['rules'];

function makeResolver(
    DateTimeImmutable $now,
    string $cacheFile,
    array $prices,
    array $clients,
    array $products,
    array $rules,
): PriceResolver {
    return new PriceResolver($prices, $clients, $products, $rules, new FileCache($cacheFile, $now), $now);
}

$failed = 0;

function expect(string $name, float $actual, float $expected): void
{
    global $failed;
    if (abs($actual - $expected) > 0.001) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: получено {$actual}, ожидалось {$expected}\n");
        return;
    }
    echo "OK   {$name}: {$actual}\n";
}

function money(float $value): string
{
    return number_format($value, 2, '.', '');
}

/**
 * @param array<string, array<string, string>> $clients
 * @param array<string, array<string, string>> $products
 * @param array<string, array<string, float>> $prices
 */
function showAppeal(
    PriceResolver $resolver,
    int $number,
    string $clientId,
    string $sku,
    int $qty,
    array $clients,
    array $products,
    array $prices,
    ?float $expectedDiscount = null,
    ?float $expectedPrice = null,
): void {
    global $failed;

    $client = $clients[$clientId];
    $product = $products[$sku];
    $type = $client['price_type'];
    $baseInFile = $prices[$sku][$type] ?? null;

    echo "{$number}. {$client['name']} ({$clientId}), тип цен {$type}\n";
    echo "   Товар {$sku}: {$product['name']}, группа «{$product['category']}»\n";
    echo "   Личная скидка клиента: {$client['personal_discount']}%\n";

    if ($baseInFile === null) {
        echo "   В prices.csv нет цены {$type} для этого товара.\n";
        try {
            $resolver->price($clientId, $sku, $qty);
            $failed++;
            fwrite(STDERR, "FAIL обращение {$number}: розница подставилась вместо ошибки\n");
        } catch (RuntimeException $e) {
            echo "   На сайте: {$e->getMessage()}\n";
        }
        echo "\n";
        return;
    }

    echo "   Базовая цена из prices.csv ({$type}): " . money($baseInFile) . "\n";

    $discount = $resolver->discountPercent($clientId, $sku, $qty);
    $price = $resolver->price($clientId, $sku, $qty);
    $sum = round($price * $qty, 2);

    echo "   Заказ: {$qty} шт., скидка на строку {$discount}%, цена за штуку " . money($price) . ", сумма " . money($sum) . "\n";

    if ($expectedDiscount !== null && $expectedPrice !== null) {
        if (abs($discount - $expectedDiscount) > 0.001 || abs($price - $expectedPrice) > 0.001) {
            $failed++;
            fwrite(STDERR, "FAIL обращение {$number}: ожидалось скидка {$expectedDiscount}%, цена " . money($expectedPrice) . "\n");
        }
    }

    echo "\n";
}

$tz = new DateTimeZone('Europe/Minsk');
$now = new DateTimeImmutable('now', $tz);

$cacheFile = tempnam(sys_get_temp_dir(), 'b2b-cache-');
$cacheExpires = $now->modify('+1 day')->format(DATE_ATOM);
file_put_contents($cacheFile, json_encode([
    'price:K-004:B-103:1' => [
        'value' => 15.48,
        'expires_at' => $cacheExpires,
        'stored_at' => $now->format(DATE_ATOM),
    ],
], JSON_UNESCAPED_UNICODE));

$resolver = makeResolver($now, $cacheFile, $prices, $clients, $products, $rules);

echo 'Сейчас для расчёта: ' . $now->format('d.m.Y H:i') . " (Минск)\n";
echo "Данные: data/clients.csv, products.csv, prices.csv, discount_rules.json\n";
echo "Пять обращений из TASK.md (ожидаемые цифры сверены с датой 22.10.2026 из задания)\n\n";

// Ожидаемые значения из разбора на 22.10.2026; на другую дату октябрьская акция может отличаться.
$taskDay = new DateTimeImmutable('2026-10-22 12:00:00', $tz);
$taskCache = tempnam(sys_get_temp_dir(), 'b2b-task-');
file_put_contents($taskCache, json_encode([
    'price:K-004:B-103:1' => [
        'value' => 15.48,
        'expires_at' => '2026-10-23T09:05:00+03:00',
        'stored_at' => '2026-10-22T09:05:00+03:00',
    ],
], JSON_UNESCAPED_UNICODE));
$taskResolver = makeResolver($taskDay, $taskCache, $prices, $clients, $products, $rules);

showAppeal($resolver, 1, 'K-003', 'B-105', 1, $clients, $products, $prices);

showAppeal($resolver, 2, 'K-001', 'B-101', 1, $clients, $products, $prices,
    $taskResolver->discountPercent('K-001', 'B-101', 1),
    $taskResolver->price('K-001', 'B-101', 1),
);

showAppeal($resolver, 3, 'K-002', 'B-106', 12, $clients, $products, $prices,
    $taskResolver->discountPercent('K-002', 'B-106', 12),
    $taskResolver->price('K-002', 'B-106', 12),
);

showAppeal($resolver, 4, 'K-004', 'B-103', 1, $clients, $products, $prices,
    $taskResolver->discountPercent('K-004', 'B-103', 1),
    $taskResolver->price('K-004', 'B-103', 1),
);

showAppeal($resolver, 5, 'K-005', 'B-107', 1, $clients, $products, $prices,
    $taskResolver->discountPercent('K-005', 'B-107', 1),
    $taskResolver->price('K-005', 'B-107', 1),
);

showAppeal($resolver, 5, 'K-006', 'B-107', 1, $clients, $products, $prices,
    $taskResolver->discountPercent('K-006', 'B-107', 1),
    $taskResolver->price('K-006', 'B-107', 1),
);

echo "Сценарий: нет цены нужного типа\n";
if (isset($prices['B-105']['RETAIL']) && !isset($prices['B-105']['DEALER'])) {
    try {
        $taskResolver->price('K-003', 'B-105', 1);
        $failed++;
        fwrite(STDERR, "FAIL нет цены DEALER для B-105: вернулась цена другого типа\n");
    } catch (RuntimeException) {
        echo "OK   K-003 B-105: цены DEALER нет, розница не подставлена\n";
    }
} else {
    $failed++;
    fwrite(STDERR, "FAIL нет цены DEALER: учебные данные изменились, сценарий не проверить\n");
}

echo "Дополнительно (даты акций и объём, на дату из задания 22.10.2026)\n";
expect('от 10 штук, K-001 B-101 скидка 3', $taskResolver->discountPercent('K-001', 'B-101', 10), 3);
expect('9 штук, объёмная скидка не включается', $taskResolver->discountPercent('K-001', 'B-101', 9), 0);

$septemberLastDay = makeResolver(
    new DateTimeImmutable('2026-09-30 12:00:00', $tz),
    $taskCache . '-sep',
    $prices,
    $clients,
    $products,
    $rules,
);
expect('30.09 сентябрь ещё действует', $septemberLastDay->discountPercent('K-001', 'B-101', 1), 7);

$octoberFirstDay = makeResolver(
    new DateTimeImmutable('2026-10-01 12:00:00', $tz),
    $taskCache . '-oct1',
    $prices,
    $clients,
    $products,
    $rules,
);
expect('01.10 сентябрь уже не действует', $octoberFirstDay->discountPercent('K-001', 'B-101', 1), 0);

$octoberLastDay = makeResolver(
    new DateTimeImmutable('2026-10-31 12:00:00', $tz),
    $taskCache . '-oct31',
    $prices,
    $clients,
    $products,
    $rules,
);
expect('31.10 октябрь ещё действует', $octoberLastDay->discountPercent('K-006', 'B-107', 1), 4);

unlink($cacheFile);
unlink($taskCache);

if ($failed > 0) {
    fwrite(STDERR, "Провалено проверок: {$failed}\n");
    exit(1);
}

echo "Все проверки прошли.\n";
