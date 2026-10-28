<?php
/**
 * Учебный мониторинг очереди обмена заказов с 1С.
 * Запуск из папки prototype: php monitor.php --orders=data/incident.json --now=2026-10-22T12:00:00+03:00
 *
 * Скрипт только читает JSON и пишет уведомления. Статусы заказов не меняет и в 1С ничего не отправляет.
 * Пороги учебные: ожидание дольше 15 минут, больше 5 попыток, явная ошибка сразу.
 */

declare(strict_types=1);

const WAIT_LIMIT_MINUTES = 15;
const ATTEMPT_LIMIT = 5;

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(main($argv));
}

function main(array $argv): int
{
    try {
        $options = parseArgs($argv);
        $result = monitorQueue($options);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        return 1;
    }

    echo $result['output'];
    if ($options['notifications'] !== null && $result['notices'] !== []) {
        appendNotices($options['notifications'], $result['notices']);
    }

    return 0;
}

/** @param list<string> $argv */
function parseArgs(array $argv): array
{
    $options = [
        'orders' => null,
        'now' => null,
        'state' => null,
        'notifications' => null,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new RuntimeException("Не понял аргумент {$arg}. Жду --orders, --now, --state, --notifications.");
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            throw new RuntimeException("Неизвестный аргумент --{$key}.");
        }
        $options[$key] = $value;
    }
    if ($options['orders'] === null || $options['now'] === null || $options['state'] === null) {
        throw new RuntimeException('Нужны --orders, --now и --state. Без файла состояния нельзя отличить повтор от новой ошибки.');
    }

    return $options;
}

/**
 * @param array{orders: string, now: string, state: string, notifications: ?string} $options
 * @return array{output: string, notices: list<string>}
 */
function monitorQueue(array $options): array
{
    $now = parseMoment($options['now'], '--now');
    $orders = readOrders($options['orders']);
    $state = readState($options['state']);
    $lines = [];
    $notices = [];
    $sent = 0;
    $pending = 0;
    $problems = 0;

    foreach ($orders as $order) {
        if ($order['status'] === 'sent') {
            $sent++;
        }
        if ($order['status'] === 'pending') {
            $pending++;
        }

        $problem = detectProblem($order, $now);
        $previous = $state[$order['id']] ?? null;

        if ($problem === null) {
            if ($previous !== null && $order['status'] === 'sent') {
                $text = formatRecovery($order['id']);
                $lines[] = $text;
                $notices[] = $text;
            }
            unset($state[$order['id']]);
            continue;
        }

        $problems++;
        if ($previous === $problem['signature']) {
            $lines[] = "Повтор не отправлен: {$order['id']}, причина не изменилась.";
            continue;
        }

        $text = formatProblem($problem);
        $lines[] = $text;
        $notices[] = $text;
        $state[$order['id']] = $problem['signature'];
    }

    writeState($options['state'], $state);
    $lines[] = sprintf(
        'Итог: всего %d, передано %d, ожидает %d, проблемных %d',
        count($orders),
        $sent,
        $pending,
        $problems
    );

    return [
        'output' => implode(PHP_EOL . PHP_EOL, $lines) . PHP_EOL,
        'notices' => $notices,
    ];
}

/** @return list<array{id: string, status: string, queued_at: DateTimeImmutable, attempts: int, error: string}> */
function readOrders(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Не удалось прочитать очередь: файл не найден ({$path}). Это сбой проверки, а не пустая очередь без проблем.");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Не удалось прочитать очередь: {$path}. Это сбой проверки, а не отсутствие проблем.");
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Не удалось разобрать JSON очереди ({$path}): {$e->getMessage()}. Очередь не прочитана, поэтому отсутствие уведомлений не значит, что проблем нет.");
    }
    if (!is_array($data) || !isset($data['orders']) || !is_array($data['orders'])) {
        throw new RuntimeException("В {$path} нет списка orders. Очередь не прочитана.");
    }

    $orders = [];
    foreach ($data['orders'] as $index => $row) {
        $orders[] = normalizeOrder($row, $index, $path);
    }

    return $orders;
}

/** @return array{id: string, status: string, queued_at: DateTimeImmutable, attempts: int, error: string} */
function normalizeOrder(mixed $row, int $index, string $path): array
{
    $place = "строка {$index} в {$path}";
    if (!is_array($row)) {
        throw new RuntimeException("Некорректный заказ, {$place}: ожидался объект.");
    }
    foreach (['id', 'status', 'queued_at', 'attempts'] as $field) {
        if (!array_key_exists($field, $row)) {
            throw new RuntimeException("Некорректный заказ, {$place}: нет поля {$field}.");
        }
    }
    $id = trim((string) $row['id']);
    $status = (string) $row['status'];
    if ($id === '' || !in_array($status, ['sent', 'pending', 'error'], true)) {
        throw new RuntimeException("Некорректный заказ {$id}, {$place}: статус должен быть sent, pending или error.");
    }
    if (!is_int($row['attempts']) || $row['attempts'] < 0) {
        throw new RuntimeException("Некорректный заказ {$id}: attempts должно быть целым числом от 0.");
    }

    return [
        'id' => $id,
        'status' => $status,
        'queued_at' => parseMoment((string) $row['queued_at'], "queued_at заказа {$id}"),
        'attempts' => $row['attempts'],
        'error' => trim((string) ($row['error'] ?? '')),
    ];
}

function parseMoment(string $value, string $label): DateTimeImmutable
{
    try {
        return new DateTimeImmutable($value);
    } catch (Exception) {
        throw new RuntimeException("Не разобрать время {$label}: {$value}.");
    }
}

/**
 * Одна текущая проблема заказа. Явная ошибка важнее числа попыток, число попыток важнее долгого ожидания.
 *
 * @param array{id: string, status: string, queued_at: DateTimeImmutable, attempts: int, error: string} $order
 * @return ?array{id: string, signature: string, reason: string, attempts: int, action: string, level: string}
 */
function detectProblem(array $order, DateTimeImmutable $now): ?array
{
    if ($order['status'] === 'sent') {
        return null;
    }
    if ($order['status'] === 'error' && $order['error'] === '') {
        throw new RuntimeException("Некорректный заказ {$order['id']}: статус error без текста ошибки.");
    }

    $waiting = waitMinutes($order['queued_at'], $now);
    if ($order['error'] !== '') {
        return problem(
            $order,
            'error:' . $order['error'],
            $order['error'],
            actionForError($order['error']),
            'ОШИБКА'
        );
    }
    if ($order['attempts'] > ATTEMPT_LIMIT) {
        return problem(
            $order,
            'attempts',
            'Неудачных попыток ' . $order['attempts'] . ', порог ' . ATTEMPT_LIMIT,
            'Открыть журнал обмена и не повторять отправку вслепую. Заказ не удалён и сам в 1С не отправлен.',
            'ОШИБКА'
        );
    }
    if ($waiting > WAIT_LIMIT_MINUTES) {
        return problem(
            $order,
            'waiting',
            "Ожидает передачи {$waiting} минут, порог " . WAIT_LIMIT_MINUTES . ' минут',
            'Проверить, обрабатывается ли очередь обмена. Заказ не удалён.',
            'ОЖИДАНИЕ'
        );
    }

    return null;
}

/** @param array{id: string, attempts: int} $order */
function problem(array $order, string $signature, string $reason, string $action, string $level): array
{
    return [
        'id' => $order['id'],
        'signature' => $signature,
        'reason' => $reason,
        'attempts' => $order['attempts'],
        'action' => $action,
        'level' => $level,
    ];
}

function actionForError(string $error): string
{
    if (str_contains($error, 'Контрагент не найден')) {
        return 'Проверить карточку контрагента в 1С. Заказ не удалён и сам в 1С не отправлен.';
    }
    if (str_contains($error, 'Ошибка соединения')) {
        return 'Проверить доступность 1С. Заказ не удалён и сам в 1С не отправлен.';
    }

    return 'Разобрать текст ошибки обмена. Заказ не удалён и сам в 1С не отправлен.';
}

function waitMinutes(DateTimeImmutable $queuedAt, DateTimeImmutable $now): int
{
    return intdiv($now->getTimestamp() - $queuedAt->getTimestamp(), 60);
}

/** @param array{level: string, id: string, reason: string, attempts: int, action: string} $problem */
function formatProblem(array $problem): string
{
    return implode(PHP_EOL, [
        "[{$problem['level']}] Обмен с 1С",
        "Заказ: {$problem['id']}",
        "Причина: {$problem['reason']}",
        "Попыток передачи: {$problem['attempts']}",
        "Действие: {$problem['action']}",
    ]);
}

function formatRecovery(string $id): string
{
    return implode(PHP_EOL, [
        '[ВОССТАНОВЛЕНО]',
        "Заказ {$id} успешно передан в 1С.",
        'Предыдущая проблема устранена.',
    ]);
}

/** @return array<string, string> */
function readState(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Не удалось прочитать память уведомлений ({$path}): {$e->getMessage()}.");
    }
    if (!is_array($data)) {
        throw new RuntimeException("Память уведомлений {$path} повреждена.");
    }

    $state = [];
    foreach ($data as $id => $signature) {
        if (is_string($id) && is_string($signature)) {
            $state[$id] = $signature;
        }
    }

    return $state;
}

/** @param array<string, string> $state */
function writeState(string $path, array $state): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать папку для памяти уведомлений: {$dir}.");
    }
    $written = file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($written === false) {
        throw new RuntimeException("Не удалось записать память уведомлений: {$path}.");
    }
}

/** @param list<string> $notices */
function appendNotices(string $path, array $notices): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать папку для уведомлений: {$dir}.");
    }
    $chunk = implode(PHP_EOL . PHP_EOL, $notices) . PHP_EOL;
    if (file_put_contents($path, $chunk, FILE_APPEND) === false) {
        throw new RuntimeException("Не удалось дописать уведомления: {$path}.");
    }
}
