<?php

declare(strict_types=1);

/**
 * Поиск вероятных дублей клиентов CRM. Только рекомендации, без изменения данных.
 *
 * Запуск из tasks/05-crm-clients: php find_duplicates.php
 */

const DATA_DIR = __DIR__ . '/data';
const REPORT_PATH = __DIR__ . '/report/duplicates-report.txt';

function lowerUtf8(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    $map = [
        'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё',
        'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м',
        'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у',
        'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ',
        'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
    ];

    return strtolower(strtr($value, $map));
}

function readCsv(string $path, string $delimiter = ';'): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть: {$path}");
    }
    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false) {
        fclose($handle);
        return [];
    }
    $header = array_map(static fn (string $h) => trim(str_replace("\xEF\xBB\xBF", '', $h)), $header);
    $rows = [];
    while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($line) === 1 && ($line[0] === null || $line[0] === '')) {
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = isset($line[$i]) ? trim((string) $line[$i]) : '';
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function normalizeUnp(string $unp): ?string
{
    $digits = preg_replace('/\D/', '', $unp) ?? '';
    if (strlen($digits) !== 9) {
        return null;
    }

    return $digits;
}

function normalizeEmail(string $email): ?string
{
    $email = lowerUtf8(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return null;
    }

    return $email;
}

function normalizePhone(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if ($digits === '') {
        return null;
    }
    // Учебные номера: сравниваем последние 9 цифр (код + номер без кода страны)
    if (strlen($digits) > 9) {
        $digits = substr($digits, -9);
    }
    if (strlen($digits) < 9) {
        return null;
    }

    return $digits;
}

function normalizeNameKey(string $name): string
{
    $name = lowerUtf8($name);
    $name = str_replace(['«', '»', '"', "'"], '', $name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = preg_replace('/\b(ооо|чуп|чтуп|одо|ип)\b/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;

    return $name;
}

/** @param list<array<string, string>> $clients */
function countTicketsByClient(array $clients, array $tickets): array
{
    $ids = array_column($clients, 'client_id');
    $counts = array_fill_keys($ids, 0);
    foreach ($tickets as $t) {
        $cid = $t['client_id'] ?? '';
        if (isset($counts[$cid])) {
            $counts[$cid]++;
        }
    }

    return $counts;
}

/**
 * Union-Find для объединения карточек по связям.
 */
final class UnionFind
{
    /** @var array<string, string> */
    private array $parent = [];

    public function add(string $id): void
    {
        if (!isset($this->parent[$id])) {
            $this->parent[$id] = $id;
        }
    }

    public function find(string $id): string
    {
        if ($this->parent[$id] !== $id) {
            $this->parent[$id] = $this->find($this->parent[$id]);
        }

        return $this->parent[$id];
    }

    public function union(string $a, string $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra !== $rb) {
            $this->parent[$rb] = $ra;
        }
    }

    /** @return array<string, list<string>> */
    public function groups(): array
    {
        $groups = [];
        foreach (array_keys($this->parent) as $id) {
            $root = $this->find($id);
            $groups[$root][] = $id;
        }
        foreach ($groups as &$members) {
            sort($members);
        }

        return array_values(array_filter($groups, static fn (array $g) => count($g) > 1));
    }
}

/**
 * @param list<array<string, string>> $clients
 * @return array<string, array<string, string>>
 */
function indexClients(array $clients): array
{
    $map = [];
    foreach ($clients as $c) {
        $map[$c['client_id']] = $c;
    }

    return $map;
}

/**
 * @param list<string> $ids
 * @param array<string, array<string, string>> $byId
 * @param array<string, int> $ticketCounts
 */
function chooseMaster(array $ids, array $byId, array $ticketCounts): string
{
    usort($ids, static function (string $a, string $b) use ($byId, $ticketCounts): int {
        $ca = $byId[$a];
        $cb = $byId[$b];
        $unpA = normalizeUnp($ca['unp'] ?? '') !== null ? 1 : 0;
        $unpB = normalizeUnp($cb['unp'] ?? '') !== null ? 1 : 0;
        if ($unpA !== $unpB) {
            return $unpB <=> $unpA;
        }
        $tA = $ticketCounts[$a] ?? 0;
        $tB = $ticketCounts[$b] ?? 0;
        if ($tA !== $tB) {
            return $tB <=> $tA;
        }

        return strcmp($ca['created_at'] ?? '', $cb['created_at'] ?? '');
    });

    return $ids[0];
}

/**
 * @param list<string> $ids
 * @param array<string, array<string, string>> $byId
 * @param array<string, int> $ticketCounts
 */
function explainMaster(string $master, array $ids, array $byId, array $ticketCounts): string
{
    $parts = [];
    $masterUnp = normalizeUnp($byId[$master]['unp'] ?? '');
    $othersWithoutUnp = false;
    foreach ($ids as $id) {
        if ($id === $master) {
            continue;
        }
        if (normalizeUnp($byId[$id]['unp'] ?? '') === null) {
            $othersWithoutUnp = true;
        }
    }
    if ($masterUnp !== null) {
        $parts[] = "есть УНП {$masterUnp}";
    }
    if ($othersWithoutUnp) {
        $parts[] = 'у части других карточек УНП пустой';
    }

    $bestTickets = $ticketCounts[$master] ?? 0;
    $ticketLeader = true;
    foreach ($ids as $id) {
        if (($ticketCounts[$id] ?? 0) > $bestTickets) {
            $ticketLeader = false;
        }
    }
    if ($ticketLeader && count($ids) > 1) {
        $parts[] = "больше или столько же обращений ({$bestTickets})";
    }
    $parts[] = 'создана раньше остальных при прочих равных (' . ($byId[$master]['created_at'] ?? '') . ')';

    return implode('; ', $parts);
}

/** @param list<array{reason: string, confidence: string}> $reasons */
function mergeConfidence(array $reasons): string
{
    foreach ($reasons as $r) {
        if ($r['confidence'] === 'точно') {
            return 'точно';
        }
    }

    return 'проверить вручную';
}

try {
    $clients = readCsv(DATA_DIR . '/crm_clients.csv');
    $tickets = readCsv(DATA_DIR . '/tickets.csv');
    $byId = indexClients($clients);
    $ticketCounts = countTicketsByClient($clients, $tickets);

    $uf = new UnionFind();
    foreach ($clients as $c) {
        $uf->add($c['client_id']);
    }

    /** @var list<array{ids: list<string>, reason: string, confidence: string}> $edges */
    $edgeLog = [];

    // Связь по УНП
    $byUnp = [];
    foreach ($clients as $c) {
        $unp = normalizeUnp($c['unp'] ?? '');
        if ($unp === null) {
            continue;
        }
        $byUnp[$unp][] = $c['client_id'];
    }
    foreach ($byUnp as $unp => $ids) {
        if (count($ids) < 2) {
            continue;
        }
        for ($i = 1, $n = count($ids); $i < $n; $i++) {
            $uf->union($ids[0], $ids[$i]);
            $edgeLog[] = [
                'ids' => [$ids[0], $ids[$i]],
                'reason' => "совпадает УНП {$unp}",
                'confidence' => 'точно',
            ];
        }
    }

    // Связь по email (если УНП не противоречат: пустой или совпадает)
    $byEmail = [];
    foreach ($clients as $c) {
        $email = normalizeEmail($c['email'] ?? '');
        if ($email === null) {
            continue;
        }
        $byEmail[$email][] = $c['client_id'];
    }
    foreach ($byEmail as $email => $ids) {
        if (count($ids) < 2) {
            continue;
        }
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                $unpA = normalizeUnp($byId[$a]['unp'] ?? '');
                $unpB = normalizeUnp($byId[$b]['unp'] ?? '');
                if ($unpA !== null && $unpB !== null && $unpA !== $unpB) {
                    continue;
                }
                $uf->union($a, $b);
                $conf = ($unpA !== null && $unpB !== null) ? 'точно' : 'проверить вручную';
                $edgeLog[] = [
                    'ids' => [$a, $b],
                    'reason' => "совпадает email {$email}" . ($unpA === null || $unpB === null ? ' (у части карточек УНП не заполнен)' : ''),
                    'confidence' => $conf,
                ];
            }
        }
    }

    // Связь по телефону + похожее имя (только если УНП не конфликтуют)
    foreach ($clients as $i => $ca) {
        foreach ($clients as $j => $cb) {
            if ($j <= $i) {
                continue;
            }
            $phoneA = normalizePhone($ca['phone'] ?? '');
            $phoneB = normalizePhone($cb['phone'] ?? '');
            if ($phoneA === null || $phoneB !== $phoneA) {
                continue;
            }
            $unpA = normalizeUnp($ca['unp'] ?? '');
            $unpB = normalizeUnp($cb['unp'] ?? '');
            if ($unpA !== null && $unpB !== null && $unpA !== $unpB) {
                continue;
            }
            $nameA = normalizeNameKey($ca['name'] ?? '');
            $nameB = normalizeNameKey($cb['name'] ?? '');
            if ($nameA === '' || $nameB === '' || $nameA !== $nameB) {
                continue;
            }
            $idA = $ca['client_id'];
            $idB = $cb['client_id'];
            $already = $uf->find($idA) === $uf->find($idB);
            if (!$already) {
                $uf->union($idA, $idB);
            }
            $edgeLog[] = [
                'ids' => [$idA, $idB],
                'reason' => "совпадает телефон (норм. {$phoneA}) и нормализованное название «{$nameA}»",
                'confidence' => 'проверить вручную',
            ];
        }
    }

    $groups = $uf->groups();

    $notDuplicates = [];
    $seenPairs = [];
    foreach ($clients as $i => $ca) {
        foreach ($clients as $j => $cb) {
            if ($j <= $i) {
                continue;
            }
            $idA = $ca['client_id'];
            $idB = $cb['client_id'];
            $pairKey = $idA . '|' . $idB;
            $unpA = normalizeUnp($ca['unp'] ?? '');
            $unpB = normalizeUnp($cb['unp'] ?? '');
            if ($unpA === null || $unpB === null || $unpA === $unpB) {
                continue;
            }
            $phoneA = normalizePhone($ca['phone'] ?? '');
            $phoneB = normalizePhone($cb['phone'] ?? '');
            $nameA = normalizeNameKey($ca['name'] ?? '');
            $nameB = normalizeNameKey($cb['name'] ?? '');
            $samePhone = $phoneA !== null && $phoneA === $phoneB;
            $sameName = $nameA !== '' && $nameA === $nameB;
            if (!$samePhone && !$sameName) {
                continue;
            }
            if (isset($seenPairs[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;
            $bits = [];
            if ($samePhone) {
                $bits[] = "совпадает телефон {$phoneA}";
            }
            if ($sameName) {
                $bits[] = "похожее название «{$nameA}»";
            }
            $notDuplicates[] = [
                'ids' => [$idA, $idB],
                'why' => implode(', ', $bits) . ", но УНП разные ({$unpA} и {$unpB}). Это разные юрлица, объединять нельзя.",
            ];
        }
    }

    $lines = [];
    $lines[] = 'Отчёт по поиску дублей CRM';
    $lines[] = 'Дата формирования: ' . date('Y-m-d H:i:s');
    $lines[] = 'Автоматическое объединение не выполнялось.';
    $lines[] = str_repeat('=', 72);
    $lines[] = '';

    $groupNum = 0;
    foreach ($groups as $memberIds) {
        $groupNum++;
        $reasonsForGroup = [];
        foreach ($edgeLog as $e) {
            if (in_array($e['ids'][0], $memberIds, true) && in_array($e['ids'][1], $memberIds, true)) {
                $reasonsForGroup[] = $e;
            }
        }
        $confidence = mergeConfidence($reasonsForGroup);
        $master = chooseMaster($memberIds, $byId, $ticketCounts);

        $lines[] = "Группа {$groupNum} [уверенность: {$confidence}]";
        $lines[] = 'Карточки:';
        foreach ($memberIds as $cid) {
            $card = $byId[$cid];
            $unpText = normalizeUnp($card['unp'] ?? '') ?? 'не заполнен';
            $lines[] = "  {$cid} | {$card['name']} | УНП {$unpText} | {$card['email']} | обращений: " . ($ticketCounts[$cid] ?? 0);
        }
        $lines[] = 'Почему дубли:';
        if ($reasonsForGroup === []) {
            $lines[] = '  — (связь через транзитивное объединение нескольких признаков)';
        } else {
            foreach ($reasonsForGroup as $r) {
                $lines[] = "  — {$r['reason']} [{$r['confidence']}]";
            }
        }

        $lines[] = "Главная карточка: {$master}";
        $lines[] = '  Почему: ' . explainMaster($master, $memberIds, $byId, $ticketCounts) . '.';

        $mergeTickets = 0;
        $lines[] = 'Обращения после объединения (перейдут на главную):';
        foreach ($memberIds as $cid) {
            $cnt = $ticketCounts[$cid] ?? 0;
            if ($cid === $master) {
                $lines[] = "  {$cid}: {$cnt} (уже на главной)";
            } else {
                $lines[] = "  {$cid}: {$cnt} → на {$master}";
                $mergeTickets += $cnt;
            }
        }
        $totalOnMaster = ($ticketCounts[$master] ?? 0) + $mergeTickets;
        $lines[] = "Итого на главной {$master}: {$totalOnMaster} обращений (переносится {$mergeTickets}).";
        $lines[] = '';
    }

    if ($groupNum === 0) {
        $lines[] = 'Групп дублей не найдено.';
        $lines[] = '';
    }

    $lines[] = str_repeat('-', 72);
    $lines[] = 'Похожи, но дублями не считаю (объединять не предлагаю):';
    $lines[] = '';
    foreach ($notDuplicates as $i => $nd) {
        $n = $i + 1;
        $lines[] = "{$n}. " . implode(' + ', $nd['ids']);
        $lines[] = '   ' . $nd['why'];
        $lines[] = '';
    }

    $lines[] = str_repeat('-', 72);
    $lines[] = 'Что не так в данных и как это исправить на уровне процесса:';
    $lines[] = '';
    $lines[] = '1. Один УНП — несколько карточек (Альфа C-1001/C-1014, Бета C-1003/C-1017): при создании';
    $lines[] = '   клиента с сайта, из 1С или вручную не выполняется поиск по нормализованному УНП.';
    $lines[] = '   Решение: уникальный индекс на УНП; перед insert — поиск и привязка к существующей карточке.';
    $lines[] = '';
    $lines[] = '2. Пустой УНП при том же email (C-1022, C-1024): юрлицо завели без обязательного УНП.';
    $lines[] = '   Решение: для B2B/юрлиц УНП обязателен; импорт из 1С всегда передаёт УНП.';
    $lines[] = '';
    $lines[] = '3. Разный формат телефона и email (пробелы, регистр, 8 вместо +375): сравнение «как ввели»';
    $lines[] = '   не находит дубль. Решение: нормализация при сохранении (как в скрипте).';
    $lines[] = '';
    $lines[] = '4. Разные написания названия (ООО «Альфа» vs Альфа-Учебный ООО): ориентир — УНП,';
    $lines[] = '   название только для подсказки менеджеру, не для автослияния без УНП/email.';
    $lines[] = '';
    $lines[] = '5. Один телефон у разных УНП (C-1005 Зета и C-1019 Ро): общий контактный номер или опечатка.';
    $lines[] = '   Решение: не объединять автоматически; уточнить у менеджера, разнести контакты при необходимости.';
    $lines[] = '';
    $lines[] = '6. Обращения привязаны к дублям (тикеты на C-1014, C-1017, C-1022, C-1024): история';
    $lines[] = '   разорвана. Решение: после ручного слияния перепривязать ticket.client_id на главную карточку,';
    $lines[] = '   журнал слияния; см. 05-design.md.';
    $lines[] = '';

    $report = implode(PHP_EOL, $lines) . PHP_EOL;
    if (!is_dir(__DIR__ . '/report')) {
        mkdir(__DIR__ . '/report', 0775, true);
    }
    file_put_contents(REPORT_PATH, $report);

    echo $report;
    echo PHP_EOL . 'Файл: ' . REPORT_PATH . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
