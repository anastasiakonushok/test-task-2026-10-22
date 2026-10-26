<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const REPORT_PATH = __DIR__ . '/report/duplicates-report.txt';

// --- 1. Нормализация данных ---
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

function normalize(string $value): string {
    return trim(preg_replace('/\s+/u', ' ', lowerUtf8($value)) ?? '');
}
function getUnp(string $unp): ?string {
    $digits = preg_replace('/\D/', '', $unp) ?: '';
    return strlen($digits) === 9 ? $digits : null;
}
function getEmail(string $email): ?string {
    $email = normalize($email);
    return str_contains($email, '@') ? $email : null;
}
function getPhone(string $phone): ?string {
    $digits = preg_replace('/\D/', '', $phone) ?: '';
    return strlen($digits) >= 9 ? substr($digits, -9) : null; // Последние 9 цифр
}
function getNameKey(string $name): string {
    $name = normalize(str_replace(['«', '»', '"', "'"], '', $name));
    return trim(preg_replace('/\b(ооо|чуп|чтуп|одо|ип)\b/u', '', $name) ?: $name);
}

// --- 2. Чтение CSV ---
function readCsv(string $path): array {
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException("Не удалось открыть: {$path}");
    $header = array_map(fn($h) => trim(str_replace("\xEF\xBB\xBF", '', (string)$h)), fgetcsv($handle, 0, ';') ?: []);
    $rows = [];
    while (($line = fgetcsv($handle, 0, ';')) !== false) {
        if (count($line) === 1 && empty($line[0])) continue;
        $row = [];
        foreach ($header as $i => $key) $row[$key] = trim((string)($line[$i] ?? ''));
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

// --- 3. Логика сравнения двух карточек ---
function getMatchReason(array $a, array $b): array {
    $unpA = getUnp($a['unp'] ?? ''); $unpB = getUnp($b['unp'] ?? '');
    if ($unpA && $unpB && $unpA !== $unpB) return ['match' => false, 'reason' => 'Разные валидные УНП', 'conf' => 'НЕТ'];
    if ($unpA && $unpA === $unpB) return ['match' => true, 'reason' => "Совпадает УНП ({$unpA})", 'conf' => 'ТОЧНО'];
    
    $emailA = getEmail($a['email'] ?? ''); $emailB = getEmail($b['email'] ?? '');
    if ($emailA && $emailA === $emailB) {
        $conf = ($unpA || $unpB) ? 'ТОЧНО' : 'ПРОВЕРИТЬ ВРУЧНУЮ';
        return ['match' => true, 'reason' => "Совпадает Email ({$emailA})", 'conf' => $conf];
    }
    
    $phoneA = getPhone($a['phone'] ?? ''); $phoneB = getPhone($b['phone'] ?? '');
    $nameA = getNameKey($a['name'] ?? ''); $nameB = getNameKey($b['name'] ?? '');
    if ($phoneA && $phoneA === $phoneB && $nameA && $nameA === $nameB) {
        return ['match' => true, 'reason' => "Совпадает Телефон + Название", 'conf' => 'ПРОВЕРИТЬ ВРУЧНУЮ'];
    }
    return ['match' => false, 'reason' => 'Нет совпадений', 'conf' => 'НЕТ'];
}

try {
    // Генерация тестовых данных, если папка пуста (для демонстрации)
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
        file_put_contents(DATA_DIR . '/crm_clients.csv', "client_id;name;unp;email;phone;created_at\n" .
            "C-1001;ООО Альфа;123456789;info@alpha.by;+375291112233;2023-01-10\n" .
            "C-1002;Альфа ЧУП;123456789;director@alpha.by;80291112233;2023-05-15\n" .
            "C-1003;Бета Трейд;;beta@mail.ru;+375334445566;2023-02-20\n" .
            "C-1004;Бета Трейд ООО;;beta@mail.ru;80334445566;2023-06-01\n" .
            "C-1005;Зета Групп;987654321;info@zeta.by;+375172003040;2023-03-01\n" .
            "C-1006;Ро Инвест;111222333;info@ro.by;+375172003040;2023-04-10\n");
            
        file_put_contents(DATA_DIR . '/tickets.csv', "ticket_id;client_id;subject;created_at\n" .
            "T-1;C-1001;Вопрос по счету;2023-01-11\n" .
            "T-2;C-1001;Доставка;2023-01-12\n" .
            "T-3;C-1002;Новый заказ;2023-05-16\n" .
            "T-4;C-1004;Возврат;2023-06-02\n");
    }

    $clients = readCsv(DATA_DIR . '/crm_clients.csv');
    $tickets = readCsv(DATA_DIR . '/tickets.csv');
    
    $byId = []; $ticketCounts = [];
    foreach ($clients as $c) { $byId[$c['client_id']] = $c; $ticketCounts[$c['client_id']] = 0; }
    foreach ($tickets as $t) { if (isset($ticketCounts[$t['client_id']])) $ticketCounts[$t['client_id']]++; }

    // --- 4. Группировка (Упрощенная транзитивная логика) ---
    $groups = [];
    foreach ($clients as $c) {
        $id = $c['client_id']; $matchedIdx = -1;
        foreach ($groups as $idx => $group) {
            if (getMatchReason($c, $byId[$group[0]])['match']) { $matchedIdx = $idx; break; }
        }
        if ($matchedIdx >= 0) $groups[$matchedIdx][] = $id;
        else $groups[] = [$id];
    }

    $duplicateGroups = array_filter($groups, fn($g) => count($g) > 1);
    $falsePositives = []; $lines = [];

    // --- 5. Формирование отчета ---
    $lines[] = "ОТЧЁТ ПО ПОИСКУ ДУБЛЕЙ CRM\nДата: " . date('Y-m-d H:i:s') . "\n" . str_repeat('=', 70);
    
    foreach ($duplicateGroups as $gIdx => $group) {
        // Сортировка для выбора "Мастера": 1. Есть УНП, 2. Больше тикетов, 3. Старше дата
        usort($group, function($a, $b) use ($byId, $ticketCounts) {
            $uA = getUnp($byId[$a]['unp'] ?? '') ? 1 : 0; $uB = getUnp($byId[$b]['unp'] ?? '') ? 1 : 0;
            if ($uA !== $uB) return $uB <=> $uA;
            $tA = $ticketCounts[$a] ?? 0; $tB = $ticketCounts[$b] ?? 0;
            if ($tA !== $tB) return $tB <=> $tA;
            return strcmp($byId[$a]['created_at'] ?? '', $byId[$b]['created_at'] ?? '');
        });

        $master = $group[0];
        $reason = getMatchReason($byId[$group[0]], $byId[$group[1]]);
        $lines[] = "\n[ГРУППА " . ($gIdx + 1) . "] Уверенность: {$reason['conf']}";
        $lines[] = "Причина: {$reason['reason']}";
        
        $mergeTickets = 0;
        foreach ($group as $cid) {
            $c = $byId[$cid]; $isMaster = ($cid === $master) ? ' (ГЛАВНАЯ)' : '';
            $unp = getUnp($c['unp'] ?? '') ?: 'не заполнен';
            $tCount = $ticketCounts[$cid] ?? 0;
            if (!$isMaster) $mergeTickets += $tCount;
            $lines[] = "  • {$cid}{$isMaster} | {$c['name']} | УНП: {$unp} | Тикетов: {$tCount}";
        }
        $masterTickets = ($ticketCounts[$master] ?? 0) + $mergeTickets;
        $lines[] = "  → Итог: После объединения на главной карточке будет {$masterTickets} обращений (переносится {$mergeTickets}).";
    }

    if (empty($duplicateGroups)) $lines[] = "\nГрупп дублей не найдено.";

    // --- 6. Поиск ложных срабатываний (Похожи, но не дубли) ---
    $lines[] = "\n" . str_repeat('-', 70) . "\nПОХОЖИ, НО НЕ ДУБЛИ (Объединять запрещено):";
    foreach ($clients as $i => $ca) {
        foreach ($clients as $j => $cb) {
            if ($j <= $i) continue;
            $unpA = getUnp($ca['unp'] ?? ''); $unpB = getUnp($cb['unp'] ?? '');
            if ($unpA && $unpB && $unpA !== $unpB) {
                $pMatch = getPhone($ca['phone'] ?? '') === getPhone($cb['phone'] ?? '');
                $nMatch = getNameKey($ca['name'] ?? '') === getNameKey($cb['name'] ?? '');
                if ($pMatch || $nMatch) {
                    $lines[] = "\n  • {$ca['client_id']} ({$ca['name']}, УНП: {$unpA})";
                    $lines[] = "  • {$cb['client_id']} ({$cb['name']}, УНП: {$unpB})";
                    $lines[] = "    Причина: Совпадают телефон/название, но УНП разные. Это разные юрлица.";
                }
            }
        }
    }

    $report = implode("\n", $lines) . "\n";
    if (!is_dir(__DIR__ . '/report')) mkdir(__DIR__ . '/report', 0775, true);
    file_put_contents(REPORT_PATH, $report);
    
    echo $report . "\n✅ Отчет сохранен: " . REPORT_PATH . "\n";

} catch (Throwable $e) {
    fwrite(STDERR, "❌ Ошибка: " . $e->getMessage() . "\n");
    exit(1);
}