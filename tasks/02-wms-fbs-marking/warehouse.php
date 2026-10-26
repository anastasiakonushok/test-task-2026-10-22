<?php

declare(strict_types=1);

/**
 * Сборка FBS: лист подбора, проверка сканов и инвентаризация.
 * Исходные файлы в data/ не изменяются.
 *
 * Запуск из tasks/02-wms-fbs-marking: php warehouse.php
 */

const DATA_DIR = __DIR__ . '/data';
const REPORT_DIR = __DIR__ . '/report';

function readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть: {$path}");
    }
    $header = fgetcsv($handle, 0, ';');
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException("Пустой файл: {$path}");
    }
    $header = array_map(static fn ($h) => trim(str_replace("\xEF\xBB\xBF", '', (string) $h)), $header);
    $rows = [];
    while (($line = fgetcsv($handle, 0, ';')) !== false) {
        if (count($line) === 1 && trim((string) ($line[0] ?? '')) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = trim((string) ($line[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function writeCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось записать: {$path}");
    }
    fputcsv($handle, $header, ';');
    foreach ($rows as $row) {
        $line = [];
        foreach ($header as $column) {
            $line[] = $row[$column] ?? '';
        }
        fputcsv($handle, $line, ';');
    }
    fclose($handle);
}

/** @return array{bySku: array<string, array>, byBarcode: array<string, array>} */
function loadCatalog(string $path): array
{
    $bySku = [];
    $byBarcode = [];
    foreach (readCsv($path) as $row) {
        $sku = $row['sku'] ?? '';
        $barcode = $row['barcode'] ?? '';
        if ($sku === '' || $barcode === '') {
            throw new RuntimeException('В каталоге есть строка без артикула или штрихкода');
        }
        $item = [
            'sku' => $sku,
            'name' => $row['name'] ?? '',
            'barcode' => $barcode,
            'marked' => ($row['marked'] ?? '') === '1',
        ];
        $bySku[$sku] = $item;
        $byBarcode[$barcode] = $item;
    }

    return ['bySku' => $bySku, 'byBarcode' => $byBarcode];
}

/** КИЗ задания: 01 + GTIN из 14 цифр (0 и EAN-13) + 21 + серийный номер. */
function parseMarkingCode(string $code): ?array
{
    if (!preg_match('/^01(\d{14})21(.+)$/', $code, $match)) {
        return null;
    }
    if ($match[2] === '' || !str_starts_with($match[1], '0')) {
        return null;
    }

    return [
        'gtin' => $match[1],
        'barcode' => substr($match[1], 1),
        'serial' => $match[2],
    ];
}

/**
 * @param array<string, array> $bySku
 * @return array{lines: list<array>, shortages: list<array>, tasks: list<array>}
 */
function buildPickList(array $tasks, array $stockRows, array $bySku): array
{
    $remaining = [];
    foreach ($stockRows as $row) {
        $sku = $row['sku'] ?? '';
        $cell = $row['cell'] ?? '';
        $qty = (int) ($row['qty'] ?? 0);
        if ($sku === '' || $cell === '' || $qty <= 0) {
            continue;
        }
        $remaining[$sku][] = ['cell' => $cell, 'qty' => $qty];
    }
    foreach ($remaining as &$cells) {
        usort($cells, static fn (array $a, array $b) => strcmp($a['cell'], $b['cell']));
    }
    unset($cells);

    usort($tasks, static function (array $a, array $b): int {
        $byDate = strcmp($a['ship_by'], $b['ship_by']);
        return $byDate !== 0 ? $byDate : strcmp($a['task_id'], $b['task_id']);
    });

    $lines = [];
    $shortages = [];
    $taskStatus = [];

    foreach ($tasks as $task) {
        $missing = [];
        foreach ($task['items'] as $item) {
            $sku = $item['sku'];
            $need = (int) $item['qty'];
            $product = $bySku[$sku] ?? null;
            if ($product === null) {
                $shortages[] = [
                    'task_id' => $task['task_id'],
                    'ship_by' => $task['ship_by'],
                    'sku' => $sku,
                    'name' => '',
                    'need' => (string) $need,
                    'taken' => '0',
                    'missing' => (string) $need,
                    'reason' => 'артикула нет в каталоге',
                ];
                $missing[] = $sku;
                continue;
            }
            $left = $need;
            foreach ($remaining[$sku] ?? [] as $index => $cell) {
                if ($left === 0) {
                    break;
                }
                $take = min($left, $cell['qty']);
                if ($take === 0) {
                    continue;
                }
                $remaining[$sku][$index]['qty'] -= $take;
                $left -= $take;
                $lines[] = [
                    'cell' => $cell['cell'],
                    'task_id' => $task['task_id'],
                    'ship_by' => $task['ship_by'],
                    'marketplace' => $task['marketplace'],
                    'sku' => $sku,
                    'name' => $product['name'],
                    'qty' => (string) $take,
                    'task_status' => 'можно собрать',
                ];
            }
            if ($left > 0) {
                $shortages[] = [
                    'task_id' => $task['task_id'],
                    'ship_by' => $task['ship_by'],
                    'sku' => $sku,
                    'name' => $product['name'],
                    'need' => (string) $need,
                    'taken' => (string) ($need - $left),
                    'missing' => (string) $left,
                    'reason' => 'не хватает остатка',
                ];
                $missing[] = $sku;
            }
        }
        $taskStatus[] = [
            'task_id' => $task['task_id'],
            'ship_by' => $task['ship_by'],
            'marketplace' => $task['marketplace'],
            'status' => $missing === [] ? 'можно собрать' : 'собрать нельзя',
            'missing' => implode(', ', $missing),
        ];
    }

    $incomplete = [];
    foreach ($taskStatus as $status) {
        if ($status['status'] !== 'можно собрать') {
            $incomplete[$status['task_id']] = true;
        }
    }
    foreach ($lines as &$line) {
        if (isset($incomplete[$line['task_id']])) {
            $line['task_status'] = 'задание неполное, не отгружать';
        }
    }
    unset($line);

    usort($lines, static function (array $a, array $b): int {
        $byCell = strcmp($a['cell'], $b['cell']);
        if ($byCell !== 0) {
            return $byCell;
        }
        $byDate = strcmp($a['ship_by'], $b['ship_by']);

        return $byDate !== 0 ? $byDate : strcmp($a['task_id'], $b['task_id']);
    });

    return ['lines' => $lines, 'shortages' => $shortages, 'tasks' => $taskStatus];
}

function loadTasks(string $path, array $bySku): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Не удалось прочитать: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['tasks']) || !is_array($data['tasks'])) {
        throw new RuntimeException('В fbs_tasks.json нет списка tasks');
    }
    $tasks = [];
    foreach ($data['tasks'] as $task) {
        $items = [];
        foreach ($task['items'] ?? [] as $item) {
            $sku = (string) ($item['sku'] ?? '');
            $barcode = (string) ($item['barcode'] ?? '');
            $catalog = $bySku[$sku] ?? null;
            if ($catalog !== null && $catalog['barcode'] !== $barcode) {
                throw new RuntimeException("В задании {$task['task_id']} штрихкод {$barcode} не совпадает с каталогом для {$sku}");
            }
            $items[] = ['sku' => $sku, 'barcode' => $barcode, 'qty' => (int) ($item['qty'] ?? 0)];
        }
        $tasks[] = [
            'task_id' => (string) ($task['task_id'] ?? ''),
            'marketplace' => (string) ($task['marketplace'] ?? ''),
            'ship_by' => (string) ($task['ship_by'] ?? ''),
            'items' => $items,
        ];
    }

    return $tasks;
}

/**
 * Повтор одного КИЗ не считается второй единицей: засчитывается только первое вхождение.
 *
 * @return list<array{task_id: string, sku: string, barcode: string, problem: string, detail: string}>
 */
function checkScans(array $tasks, array $scanRows, array $bySku, array $byBarcode): array
{
    $taskById = [];
    foreach ($tasks as $task) {
        $taskById[$task['task_id']] = $task;
    }

    $accepted = [];
    $issues = [];
    $seenCode = [];
    $codeUses = [];

    foreach ($scanRows as $row) {
        $taskId = $row['task_id'] ?? '';
        $barcode = $row['barcode'] ?? '';
        $code = $row['marking_code'] ?? '';
        $product = $byBarcode[$barcode] ?? null;
        $task = $taskById[$taskId] ?? null;

        if ($task === null) {
            $issues[] = issue($taskId, '', $barcode, 'нет такого задания', 'скан не относится ни к одному заданию');
            continue;
        }
        if ($product === null) {
            $issues[] = issue($taskId, '', $barcode, 'неизвестный штрихкод', 'штрихкода нет в каталоге');
            continue;
        }

        $expected = false;
        foreach ($task['items'] as $item) {
            if ($item['barcode'] === $barcode) {
                $expected = true;
                break;
            }
        }
        if (!$expected) {
            $issues[] = issue($taskId, $product['sku'], $barcode, 'лишний товар', "в задании нет {$product['sku']} ({$product['name']})");
            continue;
        }

        if ($product['marked']) {
            if ($code === '') {
                $issues[] = issue($taskId, $product['sku'], $barcode, 'нет кода маркировки', 'товар маркированный, КИЗ обязателен');
                continue;
            }
            $parsed = parseMarkingCode($code);
            if ($parsed === null) {
                $issues[] = issue($taskId, $product['sku'], $barcode, 'неверный код маркировки', 'ожидается 01 + GTIN из 14 цифр + 21 + серийный номер');
                continue;
            }
            if ($parsed['barcode'] !== $barcode) {
                $owner = $byBarcode[$parsed['barcode']]['sku'] ?? $parsed['barcode'];
                $issues[] = issue($taskId, $product['sku'], $barcode, 'код от другого товара', "КИЗ относится к {$owner}, а отсканирован {$product['sku']}");
                continue;
            }
            $codeUses[$code][] = $taskId;
            if (isset($seenCode[$code])) {
                $issues[] = issue($taskId, $product['sku'], $barcode, 'повтор кода маркировки', "код уже использован в {$seenCode[$code]}, повтор не считается отдельной единицей");
                continue;
            }
            $seenCode[$code] = $taskId;
        } elseif ($code !== '') {
            $issues[] = issue($taskId, $product['sku'], $barcode, 'код у немаркированного товара', 'единица засчитана, лишний код нужно убрать из отгрузки');
        }

        $accepted[$taskId][$barcode] = ($accepted[$taskId][$barcode] ?? 0) + 1;
    }

    foreach ($codeUses as $code => $taskIds) {
        $unique = array_values(array_unique($taskIds));
        if (count($unique) < 2) {
            continue;
        }
        $list = implode(', ', $unique);
        foreach ($unique as $taskId) {
            $issues[] = issue($taskId, '', '', 'один КИЗ в разных заданиях', "код {$code} встречается в заданиях {$list}");
        }
    }

    foreach ($tasks as $task) {
        if (!isset($accepted[$task['task_id']]) && !scanExists($scanRows, $task['task_id'])) {
            $issues[] = issue($task['task_id'], '', '', 'задание не сканировали', 'в журнале сканов нет ни одной строки');
        }
        foreach ($task['items'] as $item) {
            $got = $accepted[$task['task_id']][$item['barcode']] ?? 0;
            $product = $bySku[$item['sku']] ?? null;
            $name = $product['name'] ?? $item['sku'];
            if ($got < $item['qty']) {
                $issues[] = issue(
                    $task['task_id'],
                    $item['sku'],
                    $item['barcode'],
                    'не хватает сканов',
                    "нужно {$item['qty']}, принято {$got} ({$name})"
                );
            } elseif ($got > $item['qty']) {
                $issues[] = issue(
                    $task['task_id'],
                    $item['sku'],
                    $item['barcode'],
                    'лишние сканы',
                    "нужно {$item['qty']}, принято {$got} ({$name})"
                );
            }
        }
    }

    return $issues;
}

function scanExists(array $scanRows, string $taskId): bool
{
    foreach ($scanRows as $row) {
        if (($row['task_id'] ?? '') === $taskId) {
            return true;
        }
    }

    return false;
}

function issue(string $taskId, string $sku, string $barcode, string $problem, string $detail): array
{
    return [
        'task_id' => $taskId,
        'sku' => $sku,
        'barcode' => $barcode,
        'problem' => $problem,
        'detail' => $detail,
    ];
}

/**
 * Расхождение считается по ячейке. Общий остаток артикула по складу выводится отдельно
 * и не заменяет недостачу или излишек в конкретной ячейке.
 *
 * @return array{rows: list<array>, notes: list<string>}
 */
function checkInventory(array $stockRows, array $countRows, array $bySku, array $byBarcode): array
{
    $account = [];
    foreach ($stockRows as $row) {
        $key = ($row['cell'] ?? '') . '|' . ($row['sku'] ?? '');
        $account[$key] = [
            'cell' => $row['cell'],
            'sku' => $row['sku'],
            'qty' => (int) ($row['qty'] ?? 0),
        ];
    }

    $cellsVisited = [];
    $counted = [];
    $unknown = [];
    foreach ($countRows as $row) {
        $cell = $row['cell'] ?? '';
        $barcode = $row['barcode'] ?? '';
        $qty = (int) ($row['counted_qty'] ?? 0);
        $cellsVisited[$cell] = true;
        $product = $byBarcode[$barcode] ?? null;
        if ($product === null) {
            $unknown[] = [
                'cell' => $cell,
                'sku' => '',
                'barcode' => $barcode,
                'name' => '',
                'account_qty' => '0',
                'counted_qty' => (string) $qty,
                'diff' => (string) $qty,
                'type' => 'неизвестный штрихкод',
            ];
            continue;
        }
        $key = $cell . '|' . $product['sku'];
        if (!isset($counted[$key])) {
            $counted[$key] = ['cell' => $cell, 'sku' => $product['sku'], 'barcode' => $barcode, 'qty' => 0];
        }
        $counted[$key]['qty'] += $qty;
    }

    $rows = [];
    foreach ($account as $key => $item) {
        $product = $bySku[$item['sku']] ?? null;
        $barcode = $product['barcode'] ?? '';
        $name = $product['name'] ?? '';
        if (isset($counted[$key])) {
            $diff = $counted[$key]['qty'] - $item['qty'];
            $rows[] = inventoryRow($item['cell'], $item['sku'], $barcode, $name, $item['qty'], $counted[$key]['qty'], $diff, diffType($diff));
            continue;
        }
        $type = isset($cellsVisited[$item['cell']]) ? 'недостача' : 'ячейка не пересчитана';
        $rows[] = inventoryRow($item['cell'], $item['sku'], $barcode, $name, $item['qty'], 0, -$item['qty'], $type);
    }

    foreach ($counted as $key => $item) {
        if (isset($account[$key])) {
            continue;
        }
        $product = $bySku[$item['sku']];
        $rows[] = inventoryRow(
            $item['cell'],
            $item['sku'],
            $item['barcode'],
            $product['name'],
            0,
            $item['qty'],
            $item['qty'],
            'излишек в ячейке, где артикул не числится'
        );
    }

    $rows = array_merge($rows, $unknown);
    usort($rows, static fn (array $a, array $b) => strcmp($a['cell'] . $a['sku'], $b['cell'] . $b['sku']));

    $accountBySku = [];
    $countedBySku = [];
    foreach ($account as $item) {
        $accountBySku[$item['sku']] = ($accountBySku[$item['sku']] ?? 0) + $item['qty'];
    }
    foreach ($counted as $item) {
        $countedBySku[$item['sku']] = ($countedBySku[$item['sku']] ?? 0) + $item['qty'];
    }

    $notes = [];
    foreach ($accountBySku as $sku => $accountQty) {
        $countedQty = $countedBySku[$sku] ?? null;
        if ($countedQty === null) {
            continue;
        }
        $cellProblems = array_filter(
            $rows,
            static fn (array $row) => $row['sku'] === $sku && $row['type'] !== 'совпадает'
        );
        if ($cellProblems !== [] && $accountQty === $countedQty) {
            $name = $bySku[$sku]['name'] ?? $sku;
            $notes[] = "{$sku} ({$name}): по ячейкам есть расхождения, но общий остаток сходится ({$accountQty} = {$countedQty}). Похоже на перемещение между ячейками без записи в учёт.";
        }
    }

    return ['rows' => $rows, 'notes' => $notes];
}

function inventoryRow(string $cell, string $sku, string $barcode, string $name, int $account, int $counted, int $diff, string $type): array
{
    return [
        'cell' => $cell,
        'sku' => $sku,
        'barcode' => $barcode,
        'name' => $name,
        'account_qty' => (string) $account,
        'counted_qty' => (string) $counted,
        'diff' => (string) $diff,
        'type' => $type,
    ];
}

function diffType(int $diff): string
{
    if ($diff === 0) {
        return 'совпадает';
    }

    return $diff < 0 ? 'недостача' : 'излишек';
}

function reportLines(array $pick, array $issues, array $inventory): array
{
    $lines = [];
    $lines[] = 'Склад FBS: лист подбора, сканы, инвентаризация';
    $lines[] = 'Исходные файлы не изменялись.';
    $lines[] = str_repeat('=', 72);
    $lines[] = '';
    $lines[] = 'A. Лист подбора';
    $lines[] = 'Порядок: сначала задания с более ранним сроком отгрузки. Внутри артикула ячейки по алфавиту.';
    $lines[] = 'Строки маршрута отсортированы по ячейке.';
    $lines[] = '';
    foreach ($pick['tasks'] as $task) {
        $missing = $task['missing'] !== '' ? ', не хватает ' . $task['missing'] : '';
        $lines[] = "{$task['task_id']} {$task['marketplace']} до {$task['ship_by']}: {$task['status']}{$missing}";
    }
    $lines[] = '';
    $lines[] = 'Маршрут:';
    foreach ($pick['lines'] as $line) {
        $note = $line['task_status'] === 'можно собрать' ? '' : ' | ' . $line['task_status'];
        $lines[] = "{$line['cell']} | {$line['task_id']} | {$line['sku']} {$line['name']} | {$line['qty']} шт.{$note}";
    }
    $lines[] = '';
    $lines[] = 'Нехватка:';
    if ($pick['shortages'] === []) {
        $lines[] = 'нет';
    }
    foreach ($pick['shortages'] as $row) {
        $lines[] = "{$row['task_id']} | {$row['sku']} {$row['name']} | нужно {$row['need']}, взято {$row['taken']}, не хватает {$row['missing']}";
    }
    $lines[] = '';
    $lines[] = str_repeat('-', 72);
    $lines[] = 'B. Проверка сканов';
    $lines[] = '';
    if ($issues === []) {
        $lines[] = 'Ошибок нет.';
    }
    foreach ($issues as $issue) {
        $sku = $issue['sku'] !== '' ? $issue['sku'] . ' ' : '';
        $lines[] = "{$issue['task_id']} | {$sku}{$issue['problem']}: {$issue['detail']}";
    }
    $lines[] = '';
    $lines[] = str_repeat('-', 72);
    $lines[] = 'C. Инвентаризация (только расхождения)';
    $lines[] = '';
    $problems = array_values(array_filter(
        $inventory['rows'],
        static fn (array $row) => $row['type'] !== 'совпадает'
    ));
    foreach ($problems as $row) {
        $name = $row['name'] !== '' ? ' ' . $row['name'] : '';
        $barcode = $row['sku'] === '' ? $row['barcode'] . ' ' : '';
        $lines[] = "{$row['cell']} | {$barcode}{$row['sku']}{$name} | учёт {$row['account_qty']}, факт {$row['counted_qty']}, разница {$row['diff']} | {$row['type']}";
    }
    $lines[] = '';
    foreach ($inventory['notes'] as $note) {
        $lines[] = $note;
    }
    $lines[] = '';

    return $lines;
}

try {
    $catalog = loadCatalog(DATA_DIR . '/catalog.csv');
    $stock = readCsv(DATA_DIR . '/stock_cells.csv');
    $tasks = loadTasks(DATA_DIR . '/fbs_tasks.json', $catalog['bySku']);
    $scans = readCsv(DATA_DIR . '/scans.csv');
    $inventoryCount = readCsv(DATA_DIR . '/inventory_count.csv');

    $pick = buildPickList($tasks, $stock, $catalog['bySku']);
    $issues = checkScans($tasks, $scans, $catalog['bySku'], $catalog['byBarcode']);
    $inventory = checkInventory($stock, $inventoryCount, $catalog['bySku'], $catalog['byBarcode']);

    if (!is_dir(REPORT_DIR) && !mkdir(REPORT_DIR, 0775, true) && !is_dir(REPORT_DIR)) {
        throw new RuntimeException('Не удалось создать папку report');
    }

    writeCsv(REPORT_DIR . '/pick-list.csv', ['cell', 'task_id', 'ship_by', 'marketplace', 'sku', 'name', 'qty', 'task_status'], $pick['lines']);
    writeCsv(REPORT_DIR . '/pick-shortages.csv', ['task_id', 'ship_by', 'sku', 'name', 'need', 'taken', 'missing', 'reason'], $pick['shortages']);
    writeCsv(REPORT_DIR . '/scan-issues.csv', ['task_id', 'sku', 'barcode', 'problem', 'detail'], $issues);
    writeCsv(
        REPORT_DIR . '/inventory.csv',
        ['cell', 'sku', 'barcode', 'name', 'account_qty', 'counted_qty', 'diff', 'type'],
        $inventory['rows']
    );

    $text = implode(PHP_EOL, reportLines($pick, $issues, $inventory)) . PHP_EOL;
    file_put_contents(REPORT_DIR . '/report.txt', $text);
    echo $text;
    echo 'Файлы: ' . REPORT_DIR . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
