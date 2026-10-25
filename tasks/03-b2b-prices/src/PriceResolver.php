<?php
/**
 * Учебный код для тестового задания. Не относится к реальным системам.
 * Расчёт цены товара для клиента B2B-сайта: тип цен клиента -> базовая цена -> скидка -> кеш.
 *
 * Запуск из папки tasks/03-b2b-prices:
 *   php run.php K-001 B-101
 *   php run.php K-002 B-106 12
 *   php check.php
 * «Сейчас» задано в run.php: 22.10.2026 12:00, Минск.
 * run.php дописывает цену в var/cache.json. check.php пишет во временный файл и исходный кеш не трогает.
 */

declare(strict_types=1);

/**
 * Кеш готовой цены в json-файле.
 * Живую запись (expires_at ещё не наступил) отдаёт как есть и заново не считает.
 */
final class FileCache
{
    public function __construct(private string $file, private DateTimeImmutable $now) {}

    public function remember(string $key, int $ttlSeconds, callable $compute): float
    {
        $data = is_file($this->file) ? (json_decode((string) file_get_contents($this->file), true) ?: []) : [];

        if (isset($data[$key]) && new DateTimeImmutable($data[$key]['expires_at']) > $this->now) {
            return (float) $data[$key]['value'];
        }

        $value = $compute();
        $data[$key] = [
            'value'      => $value,
            'expires_at' => $this->now->modify("+{$ttlSeconds} seconds")->format(DATE_ATOM),
            'stored_at'  => $this->now->format(DATE_ATOM),
        ];
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $value;
    }
}

final class PriceResolver
{
    /**
     * @param array<string, array<string, float>> $prices   [sku][price_type] => price
     * @param array<string, array<string, string>> $clients [client_id] => row
     * @param array<string, array<string, string>> $products [sku] => row
     * @param list<array<string, mixed>> $rules
     */
    public function __construct(
        private array $prices,
        private array $clients,
        private array $products,
        private array $rules,
        private FileCache $cache,
        private DateTimeImmutable $now,
    ) {}

    /**
     * Базовая цена — только тип цен этого клиента.
     * Если строки нет в выгрузке 1С, розницу не подставляем: иначе дилер видит чужую цену.
     */
    public function basePrice(string $clientId, string $sku): float
    {
        $type = $this->clients[$clientId]['price_type'];
        if (!isset($this->prices[$sku][$type])) {
            throw new RuntimeException("Нет цены {$type} для товара {$sku}. Розничную вместо неё не подставляем.");
        }

        return $this->prices[$sku][$type];
    }

    /**
     * Одна скидка: максимальная из личной и тех правил, которые подходят по дате, количеству и категории.
     * Проценты не складываются.
     */
    public function discountPercent(string $clientId, string $sku, int $qty): float
    {
        $candidates = [(float) $this->clients[$clientId]['personal_discount']];

        foreach ($this->rules as $rule) {
            if (!$this->isActive($rule)) {
                continue;
            }
            if ($rule['type'] === 'volume' && $qty >= $rule['min_qty']) {
                $candidates[] = (float) $rule['percent'];
            }
            if ($rule['type'] === 'promo' && $rule['category'] === $this->products[$sku]['category']) {
                $candidates[] = (float) $rule['percent'];
            }
        }

        return max($candidates);
    }

    /**
     * Правило действует от valid_from до valid_to, последний день входит.
     * Даты разбираются как календарные, а не сравниваются текстом d.m.Y.
     */
    private function isActive(array $rule): bool
    {
        $today = $this->now->setTime(0, 0);
        $from = $this->parseDate($rule['valid_from']);
        $to = $this->parseDate($rule['valid_to']);

        return $today >= $from && $today <= $to;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, $this->now->getTimezone());
        if ($date === false) {
            throw new RuntimeException("Не разобрать дату правила: {$value}");
        }

        return $date;
    }

    /**
     * Цена на сайте: базовая * (100 - скидка) / 100, до копеек.
     * В ключе кеша есть тип цены: после перевода клиента на другой тип старая сумма не отдаётся.
     * Запись живёт сутки.
     */
    public function price(string $clientId, string $sku, int $qty = 1): float
    {
        $type = $this->clients[$clientId]['price_type'];

        return $this->cache->remember("price:{$clientId}:{$type}:{$sku}:{$qty}", 86400, function () use ($clientId, $sku, $qty): float {
            $base = $this->basePrice($clientId, $sku);
            $discount = $this->discountPercent($clientId, $sku, $qty);

            return round($base * (100 - $discount) / 100, 2);
        });
    }
}
