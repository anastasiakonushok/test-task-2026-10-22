<?php
/**
 * Проверка мониторинга без PHPUnit.
 * Запуск из папки prototype: php tests/test-monitor.php
 * Учебные JSON не изменяет. Память уведомлений пишет во временную папку.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/monitor.php';

$failed = 0;
$now = '2026-10-22T12:00:00+03:00';
$data = dirname(__DIR__) . '/data';

function check(bool $ok, string $name): void
{
    global $failed;
    if ($ok) {
        echo "OK   {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

function freshState(): string
{
    $path = tempnam(sys_get_temp_dir(), 'monitor-state-');
    if ($path === false) {
        throw new RuntimeException('Не создать временный файл состояния');
    }
    file_put_contents($path, '{}');

    return $path;
}

/** @return array{output: string, notices: list<string>} */
function runFile(string $orders, string $state): array
{
    return monitorQueue([
        'orders' => $orders,
        'now' => '2026-10-22T12:00:00+03:00',
        'state' => $state,
        'notifications' => null,
    ]);
}

try {
    $state = freshState();
    $ok = runFile($data . '/ok.json', $state);
    check($ok['notices'] === [], 'успешная очередь без уведомлений');
    check(str_contains($ok['output'], 'всего 2, передано 2, ожидает 0, проблемных 0'), 'статистика успешной очереди');
    unlink($state);

    $state = freshState();
    $first = runFile($data . '/incident.json', $state);
    check(str_contains($first['output'], 'Заказ: B2B-9103') && str_contains($first['output'], '25 минут'), 'зависший заказ 25 минут');
    check(!str_contains($first['output'], 'Заказ: B2B-9102'), 'ожидание 5 минут без уведомления');
    check(str_contains($first['output'], 'Заказ: B2B-9104') && str_contains($first['output'], 'Контрагент не найден'), 'ошибка: контрагент не найден');
    check(str_contains($first['output'], 'Заказ: B2B-9105') && str_contains($first['output'], 'Ошибка соединения'), 'ошибка соединения');
    check(str_contains($first['output'], 'Заказ: B2B-9106') && str_contains($first['output'], 'Неудачных попыток 6'), 'больше 5 попыток');
    check(str_contains($first['output'], 'всего 6, передано 1, ожидает 3, проблемных 4'), 'статистика инцидента');

    $second = runFile($data . '/incident.json', $state);
    check($second['notices'] === [], 'повторный запуск не дублирует уведомления');
    check(str_contains($second['output'], 'Повтор не отправлен: B2B-9104'), 'повтор той же ошибки отмечен и не отправлен');

    $changed = runFile($data . '/changed-error.json', $state);
    check(count($changed['notices']) === 1 && str_contains($changed['notices'][0], 'B2B-9104') && str_contains($changed['notices'][0], 'Ошибка соединения'), 'новая ошибка даёт одно новое уведомление');
    unlink($state);

    $state = freshState();
    runFile($data . '/incident.json', $state);
    $recovered = runFile($data . '/recovered.json', $state);
    check(count($recovered['notices']) === 1 && str_contains($recovered['notices'][0], '[ВОССТАНОВЛЕНО]') && str_contains($recovered['notices'][0], 'B2B-9104'), 'восстановление ранее проблемного заказа');
    check(str_contains($recovered['output'], 'Повтор не отправлен: B2B-9105'), 'остальные проблемы повторно не отправляются');
    unlink($state);

    $badJson = tempnam(sys_get_temp_dir(), 'monitor-bad-');
    $missing = sys_get_temp_dir() . '/monitor-missing-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($badJson, '{');
    $brokenSeen = false;
    try {
        runFile($badJson, freshState());
    } catch (RuntimeException $e) {
        $brokenSeen = str_contains($e->getMessage(), 'не прочитана');
    }
    check($brokenSeen, 'битый JSON — это сбой чтения, а не пустая очередь');

    $missingSeen = false;
    try {
        runFile($missing, freshState());
    } catch (RuntimeException $e) {
        $missingSeen = str_contains($e->getMessage(), 'файл не найден');
    }
    check($missingSeen, 'отсутствующий файл виден как сбой проверки');
    unlink($badJson);
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, 'FAIL исключение: ' . $e->getMessage() . PHP_EOL);
}

if ($failed > 0) {
    fwrite(STDERR, "Провалено проверок: {$failed}\n");
    exit(1);
}

echo "Все проверки прошли.\n";
