<?php

declare(strict_types=1);

use BillTo\Shop\Model\Buyer;
use BillTo\Shop\Model\Line;
use BillTo\Shop\Model\Order;

function buyer(string $country = 'PL', string $taxId = '', string $name = 'ACME sp. z o.o.'): Buyer
{
    return new Buyer($name, $country, $taxId, 'Testowa 5', '30-001 Kraków', 'jan@acme.pl', '600100200');
}

function product(string $id, string $name, float $qty, float $net, float $gross, ?float $tax, bool $service = false): Line
{
    return new Line($id, Line::TYPE_PRODUCT, $name, $qty, $net, $gross, $tax, $service);
}

function shipping(string $id, float $net, float $gross, ?float $tax = 23.0): Line
{
    return new Line($id, Line::TYPE_SHIPPING, 'Kurier', 1.0, $net, $gross, $tax);
}

function fee(string $id, string $name, float $net, float $gross, ?float $tax = 23.0): Line
{
    return new Line($id, Line::TYPE_FEE, $name, 1.0, $net, $gross, $tax);
}

/**
 * @param Line[] $lines
 */
function order(Buyer $buyer, array $lines, string $gateway = 'cheque'): Order
{
    return new Order('42', '42', 'PLN', '2026-09-14', $buyer, $lines, $gateway, 'Przelewy24', '');
}
