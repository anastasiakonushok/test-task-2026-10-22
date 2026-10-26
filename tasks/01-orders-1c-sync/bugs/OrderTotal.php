<?php
/**
 * Учебный код для тестового задания. Не относится к реальным системам.
 *
 * Расчёт итогов заказа: сумма строк, скидка в процентах, выделение НДС.
 * Цены в заказе указаны С УЧЁТОМ НДС 20 %.
 *
 * Запуск из папки bugs: php OrderTotal.php
 *
 * Исправлены три ошибки:
 * 1. subtotal — цену нельзя приводить к (int), иначе теряются копейки.
 * 2. vatIncluded — при цене «с НДС» доля налога это rate/(100+rate), не rate/100.
 * 3. calculate — НДС считаем от суммы после скидки (total), не от subtotal.
 */

declare(strict_types=1);

final class OrderTotal
{
    public const VAT_RATE = 20; // ставка НДС, %

    /**
     * Сумма строк заказа: цена за штуку × количество по каждой позиции.
     *
     * @param array<int, array{sku: string, qty: int, price: float}> $items
     */
    public static function subtotal(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            // Цена с копейками (3.20, 18.50). (int) обрезало бы до 3 и 18.
            $sum += $item['price'] * $item['qty'];
        }

        return round($sum, 2);
    }

    /**
     * Скидка в процентах, допустимо от 0 до 100 включительно.
     * Недопустимая скидка должна приводить к исключению.
     */
    public static function applyDiscount(float $subtotal, int $percent): float
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException("Недопустимая скидка: {$percent}%");
        }

        return round($subtotal * (100 - $percent) / 100, 2);
    }

    /**
     * Сколько рублей НДС уже «сидит» внутри суммы, если цена указана с НДС.
     *
     * Пример: 120 BYN с НДС 20 % → налог 20, а не 120×0,2=24.
     * Формула: сумма × ставка / (100 + ставка).
     */
    public static function vatIncluded(float $totalWithVat): float
    {
        return round($totalWithVat * self::VAT_RATE / (100 + self::VAT_RATE), 2);
    }

    /**
     * @param array<int, array{sku: string, qty: int, price: float}> $items
     * @return array{subtotal: float, total: float, vat: float}
     */
    public static function calculate(array $items, int $discountPercent = 0): array
    {
        $subtotal = self::subtotal($items);
        $total    = self::applyDiscount($subtotal, $discountPercent);

        return [
            'subtotal' => $subtotal,
            'total'    => $total,
            // НДС от того, что платит покупатель после скидки, не от суммы до скидки.
            'vat'      => self::vatIncluded($total),
        ];
    }
}

// ---------------------------------------------------------------------------
// Самопроверка. Ожидаемые значения посчитаны вручную и верны.
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $items = [
        ['sku' => 'TS-1001', 'qty' => 2, 'price' => 3.20],
        ['sku' => 'TS-1006', 'qty' => 1, 'price' => 18.50],
        ['sku' => 'TS-1034', 'qty' => 1, 'price' => 1890.00],
    ];

    $cases = [
        ['discount' => 0,  'expect' => ['subtotal' => 1914.90, 'total' => 1914.90, 'vat' => 319.15]],
        ['discount' => 10, 'expect' => ['subtotal' => 1914.90, 'total' => 1723.41, 'vat' => 287.24]],
    ];

    $failed = 0;
    foreach ($cases as $case) {
        $got = OrderTotal::calculate($items, $case['discount']);
        foreach ($case['expect'] as $key => $value) {
            $ok = abs($got[$key] - $value) < 0.005;
            $failed += $ok ? 0 : 1;
            printf("[%s] скидка %d%%, %s: ожидалось %.2f, получено %.2f\n",
                $ok ? 'OK  ' : 'FAIL', $case['discount'], $key, $value, $got[$key]);
        }
    }

    try {
        OrderTotal::applyDiscount(100.0, 150);
        echo "[FAIL] скидка 150% должна вызывать исключение\n";
        $failed++;
    } catch (InvalidArgumentException) {
        echo "[OK  ] скидка 150% отклонена\n";
    }

    echo $failed === 0 ? "Все проверки пройдены\n" : "Ошибок: {$failed}\n";
    exit($failed === 0 ? 0 : 1);
}
