<?php
/**
 * Сверка заказов маркетплейса с выгрузкой 1С.
 * Запуск из папки tasks/01-orders-1c-sync: php reconcile.php
 *
 * Пишет report/report.csv и var/reconcile.log
 */

declare(strict_types=1);

const PRICE_EPSILON = 0.011;

function parse1cNumber(string $value): float
{
    $value = trim($value);
    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);

    return (float) $value;
}

/** @return array<string, array{code: string, name: string, price: float, stock: float}> */
function loadNomenclature(string $csvPath, string $logFile): array
{
    $fh = fopen($csvPath, 'r');
    if ($fh === false) {
        throw new RuntimeException("Не открыть {$csvPath}");
    }
    $head = fgetcsv($fh, null, ';', '"', '');
    if ($head !== false && isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
    }
    $map = [];
    while (($row = fgetcsv($fh, null, ';', '"', '')) !== false) {
        if (count($row) < 6) {
            file_put_contents($logFile, date('[c] ') . "Пропущена короткая строка CSV\n", FILE_APPEND);
            continue;
        }
        $data = array_combine($head, $row);
        $sku = strtoupper(trim((string) ($data['Артикул'] ?? '')));
        if ($sku === '') {
            continue;
        }
        if (isset($map[$sku])) {
            file_put_contents(
                $logFile,
                date('[c] ') . "Дубль артикула в 1С: {$sku}, беру первую строку\n",
                FILE_APPEND
            );
            continue;
        }
        $map[$sku] = [
            'code'  => (string) ($data['Код'] ?? ''),
            'name'  => (string) ($data['Наименование'] ?? ''),
            'price' => parse1cNumber((string) ($data['Цена'] ?? '0')),
            'stock' => parse1cNumber((string) ($data['Остаток'] ?? '0')),
        ];
    }
    fclose($fh);

    return $map;
}

/** @param resource $reportFh */
function writeReportRow($reportFh, string $orderId, string $status, string $sku, string $issue, string $detail, string $action): void
{
    fputcsv($reportFh, [$orderId, $status, $sku, $issue, $detail, $action], ';', '"', '');
}

$baseDir = __DIR__;
$logFile = $baseDir . '/var/reconcile.log';
$reportDir = $baseDir . '/report';
if (!is_dir($baseDir . '/var')) {
    mkdir($baseDir . '/var', 0777, true);
}
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}
file_put_contents($logFile, date('[c] ') . "Старт сверки\n");

$nomenclature = loadNomenclature($baseDir . '/data/nomenclature_1c.csv', $logFile);

$ordersJson = file_get_contents($baseDir . '/data/orders.json');
if ($ordersJson === false) {
    file_put_contents($logFile, date('[c] ') . "Не прочитать orders.json\n", FILE_APPEND);
    exit(1);
}
$payload = json_decode($ordersJson, true);
if (!is_array($payload)) {
    file_put_contents($logFile, date('[c] ') . "Битый JSON orders.json\n", FILE_APPEND);
    exit(1);
}
$orders = $payload['data']['orders'] ?? null;
if (!is_array($orders)) {
    file_put_contents($logFile, date('[c] ') . "Нет data.orders в JSON\n", FILE_APPEND);
    exit(1);
}

/** @var array<string, float> */
$stockLeft = [];
foreach ($nomenclature as $sku => $row) {
    $stockLeft[$sku] = $row['stock'];
}

$seenOrderIds = [];
$reportPath = $reportDir . '/report.csv';
$reportFh = fopen($reportPath, 'w');
if ($reportFh === false) {
    throw new RuntimeException('Не создать report.csv');
}
fputcsv($reportFh, ['order_id', 'status', 'sku', 'issue', 'detail', 'action_for_manager'], ';', '"', '');

foreach ($orders as $index => $order) {
    if (!is_array($order)) {
        file_put_contents($logFile, date('[c] ') . "Заказ #{$index} не объект\n", FILE_APPEND);
        continue;
    }
    $orderId = (string) ($order['id'] ?? '');
    $status = (string) ($order['status'] ?? 'unknown');
    if ($orderId === '') {
        file_put_contents($logFile, date('[c] ') . "Заказ #{$index} без id\n", FILE_APPEND);
        writeReportRow($reportFh, '?', $status, '', 'bad_order', 'нет id', 'проверить выгрузку API');
        continue;
    }

    if (isset($seenOrderIds[$orderId])) {
        writeReportRow(
            $reportFh,
            $orderId,
            $status,
            '',
            'duplicate_order',
            'тот же id уже обрабатывали',
            'не отправлять в 1С повторно, оставить одну копию'
        );
        continue;
    }
    $seenOrderIds[$orderId] = true;

    if ($status === 'cancelled') {
        writeReportRow(
            $reportFh,
            $orderId,
            $status,
            '',
            'cancelled',
            'заказ отменён',
            'в 1С не передаём, остаток не резервируем'
        );
        continue;
    }

    $items = $order['items'] ?? null;
    if (!is_array($items) || $items === []) {
        writeReportRow(
            $reportFh,
            $orderId,
            $status,
            '',
            'empty_order',
            'нет строк товаров',
            'уточнить у маркетплейса или отложить'
        );
        continue;
    }

    foreach ($items as $lineIndex => $item) {
        if (!is_array($item)) {
            file_put_contents($logFile, date('[c] ') . "{$orderId} строка {$lineIndex} не объект\n", FILE_APPEND);
            continue;
        }
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        if ($sku === '') {
            writeReportRow($reportFh, $orderId, $status, '', 'bad_line', 'нет sku', 'исправить данные заказа');
            continue;
        }
        if (!isset($item['quantity']) || !is_numeric($item['quantity'])) {
            file_put_contents($logFile, date('[c] ') . "{$orderId} {$sku}: нет quantity\n", FILE_APPEND);
            writeReportRow($reportFh, $orderId, $status, $sku, 'bad_line', 'нет количества', 'запросить данные у маркетплейса');
            continue;
        }
        $qty = (int) $item['quantity'];
        if (!isset($item['price']) || !is_numeric($item['price'])) {
            file_put_contents($logFile, date('[c] ') . "{$orderId} {$sku}: нет price\n", FILE_APPEND);
            writeReportRow($reportFh, $orderId, $status, $sku, 'bad_line', 'нет цены в строке', 'запросить цену у маркетплейса');
            continue;
        }
        $orderPrice = (float) $item['price'];

        if (!isset($nomenclature[$sku])) {
            writeReportRow(
                $reportFh,
                $orderId,
                $status,
                $sku,
                'sku_missing_in_1c',
                'артикула нет в выгрузке',
                'завести в 1С или снять с маркетплейса'
            );
            continue;
        }

        $ref = $nomenclature[$sku];
        if (abs($orderPrice - $ref['price']) > PRICE_EPSILON) {
            writeReportRow(
                $reportFh,
                $orderId,
                $status,
                $sku,
                'price_mismatch',
                "заказ {$orderPrice}, 1С {$ref['price']}",
                'сверить прайс маркетплейса и 1С до отправки'
            );
        }

        if (!isset($stockLeft[$sku])) {
            $stockLeft[$sku] = $ref['stock'];
        }
        if ($qty > $stockLeft[$sku]) {
            writeReportRow(
                $reportFh,
                $orderId,
                $status,
                $sku,
                'insufficient_stock',
                "нужно {$qty}, осталось по учёту {$stockLeft[$sku]} (с учётом предыдущих заказов)",
                'уменьшить количество, дождаться прихода или не подтверждать заказ'
            );
        } else {
            $stockLeft[$sku] -= $qty;
        }
    }
}

fclose($reportFh);
file_put_contents($logFile, date('[c] ') . "Готово, отчёт: {$reportPath}\n", FILE_APPEND);

echo "Отчёт: {$reportPath}\n";
echo "Лог: {$logFile}\n";
