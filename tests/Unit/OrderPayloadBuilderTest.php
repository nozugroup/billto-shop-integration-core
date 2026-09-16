<?php

declare(strict_types=1);

use BillTo\Shop\BuyerScenario;
use BillTo\Shop\MarkPaidPayload;
use BillTo\Shop\OrderPayloadBuilder;
use BillTo\Shop\Settings;
use BillTo\Shop\VatMapper;

function settings(array $overrides = []): Settings
{
    $settings = new Settings;
    $settings->source = 'woocommerce';
    $settings->orderNoteLabel = 'Zamówienie WooCommerce';
    $settings->unpaidGateways = ['cod', 'bacs'];

    foreach ($overrides as $key => $value) {
        $settings->$key = $value;
    }

    return $settings;
}

it('blocks an EU consumer order without VAT in Polish-rates mode and maps it when configured so', function () {
    $order = order(buyer('DE', ''), [
        product('10', 'Buch', 1, 50.0, 50.0, null),
        product('11', 'Beratung', 1, 200.0, 200.0, 0.0, true),
    ]);

    $blocked = (new OrderPayloadBuilder(settings()))->build($order);
    expect($blocked['scenario'])->toBe(BuyerScenario::EU_B2C)
        ->and($blocked['blocker'])->toBe(Settings::BLOCKER_EU_CONSUMER_NO_VAT);

    $mapped = (new OrderPayloadBuilder(settings(['euConsumerNoVat' => Settings::EU_CONSUMER_NO_VAT_MAP])))->build($order);
    expect($mapped['blocker'])->toBeNull()
        ->and(array_column($mapped['payload']['items'], 'vat_type'))->toBe(['zw', '0 KR']);

    $withVat = (new OrderPayloadBuilder(settings()))->build(order(buyer('DE', ''), [product('10', 'Buch', 1, 50.0, 52.5, 5.0)]));
    expect($withVat['blocker'])->toBeNull()->and($withVat['payload']['items'][0]['vat_type'])->toBe('5');

    $ossMode = (new OrderPayloadBuilder(settings(['ossMode' => Settings::OSS_INVOICE])))->build($order);
    expect($ossMode['blocker'])->toBeNull();

    $domestic = (new OrderPayloadBuilder(settings()))->build(order(buyer('PL', ''), [product('10', 'Książka', 1, 50.0, 50.0, null)]));
    expect($domestic['blocker'])->toBeNull();
});

it('invoices a foreign buyer with Polish rates when the shop charged VAT anyway', function () {
    $euCompany = order(buyer('IE', 'IE6388047V'), [product('10', 'Poster', 3, 87.0, 107.01, 23.0), shipping('20', 10.0, 12.3)]);
    $result = (new OrderPayloadBuilder(settings()))->build($euCompany);
    expect($result['scenario'])->toBe(BuyerScenario::EU_B2B_DOMESTIC)
        ->and(array_column($result['payload']['items'], 'vat_type'))->toBe(['23', '23'])
        ->and($result['warnings'])->toBe([Settings::WARNING_FOREIGN_TAXED])
        ->and($result['payload']['integration_warnings'])->toBe([Settings::WARNING_FOREIGN_TAXED]);

    $nonEu = order(buyer('GB', ''), [product('10', 'T-shirt', 1, 19.12, 23.52, 23.0)]);
    $result = (new OrderPayloadBuilder(settings()))->build($nonEu);
    expect($result['scenario'])->toBe(BuyerScenario::NON_EU_DOMESTIC)
        ->and($result['payload']['items'][0]['vat_type'])->toBe('23');

    // Shop applied 0% (reverse charge / export configured): the foreign mapping stands.
    $zeroRated = order(buyer('IE', 'IE6388047V'), [product('10', 'Poster', 3, 87.0, 87.0, 0.0), shipping('20', 10.0, 10.0, 0.0)]);
    $result = (new OrderPayloadBuilder(settings()))->build($zeroRated);
    expect($result['scenario'])->toBe(BuyerScenario::EU_B2B)
        ->and(array_column($result['payload']['items'], 'vat_type'))->toBe(['0 WDT', '0 WDT']);

    // Opt-out keeps 0% even though VAT was charged.
    $kept = (new OrderPayloadBuilder(settings(['foreignTaxedFollowsShop' => false])))->build($euCompany);
    expect($kept['scenario'])->toBe(BuyerScenario::EU_B2B)->and($kept['payload']['items'][0]['vat_type'])->toBe('0 WDT');
});

it('gives untaxed shipping the rate of the goods on domestic orders unless disabled', function () {
    $order = order(buyer('PL', ''), [
        product('10', 'Książka', 1, 50.0, 52.5, 5.0),
        shipping('20', 9.92, 9.92, null),
    ]);

    $followed = (new OrderPayloadBuilder(settings()))->build($order);
    expect(array_column($followed['payload']['items'], 'vat_type'))->toBe(['5', '5'])
        ->and($followed['warnings'])->toBe([Settings::WARNING_UNTAXED_EXTRAS]);

    $mapped = (new OrderPayloadBuilder(settings(['untaxedExtrasFollowGoods' => false])))->build($order);
    expect(array_column($mapped['payload']['items'], 'vat_type'))->toBe(['5', 'zw'])
        ->and($mapped['warnings'])->toBe([])
        ->and($mapped['payload'])->not->toHaveKey('integration_warnings');

    // Goods without VAT (exempt seller): nothing to follow, the no-tax mapping stays.
    $exempt = (new OrderPayloadBuilder(settings()))->build(order(buyer('PL', ''), [product('10', 'Książka', 1, 50.0, 50.0, null), shipping('20', 9.92, 9.92, null)]));
    expect(array_column($exempt['payload']['items'], 'vat_type'))->toBe(['zw', 'zw']);

    // Foreign B2B keeps its own mapping (0 WDT / np).
    $wdt = (new OrderPayloadBuilder(settings()))->build(order(buyer('DE', 'DE811907980'), [product('10', 'Widget', 1, 100.0, 100.0, 0.0), shipping('20', 10.0, 10.0, null)]));
    expect(array_column($wdt['payload']['items'], 'vat_type'))->toBe(['0 WDT', '0 WDT']);
});

it('builds a gross-mode domestic B2B order with shipping and a line map', function () {
    $order = order(buyer('PL', '526-104-08-28'), [
        product('10', 'Kubek [KUB-1]', 2, 80.0, 98.40, 23.0),
        shipping('20', 9.92, 12.20),
    ]);

    $result = (new OrderPayloadBuilder(settings()))->build($order);
    $payload = $result['payload'];

    expect($result['scenario'])->toBe(BuyerScenario::PL_B2B)
        ->and($result['hasNegativeLines'])->toBeFalse()
        ->and($result['lineMap'])->toBe(['10' => 1, '20' => 2])
        ->and($payload)->toMatchArray([
            'external_id' => '42', 'source' => 'woocommerce', 'status' => 'confirmed', 'send_confirmation' => false,
            'currency' => 'PLN', 'order_date' => '2026-09-14', 'amount_entry_mode' => 'gross',
        ])
        ->and($payload['buyer'])->toBe([
            'name' => 'ACME sp. z o.o.', 'tax_type' => 'local', 'tax_number' => '5261040828', 'country_code' => 'PL',
            'address_line_1' => 'Testowa 5', 'address_line_2' => '30-001 Kraków', 'phone' => '600100200', 'email' => 'jan@acme.pl',
        ])
        ->and($payload['items'])->toBe([
            ['name' => 'Kubek [KUB-1]', 'quantity' => 2.0, 'units' => 'szt.', 'unit_price' => 40.0, 'vat_type' => '23', 'unit_price_gross' => 49.2],
            ['name' => 'Dostawa: Kurier', 'quantity' => 1.0, 'units' => 'usł.', 'unit_price' => 9.92, 'vat_type' => '23', 'unit_price_gross' => 12.2],
        ])
        ->and($payload['notes'])->toBe('Zamówienie WooCommerce #42 | Płatność: Przelewy24');
});

it('omits gross prices in net mode and treats consumers as tax_type none', function () {
    $order = order(buyer('PL', '', 'Jan Kowalski'), [product('1', 'Książka', 1, 50.0, 52.5, 5.0)]);
    $result = (new OrderPayloadBuilder(settings(['amountMode' => Settings::AMOUNT_NET])))->build($order);

    expect($result['payload']['amount_entry_mode'])->toBe('net')
        ->and($result['payload']['items'][0])->not->toHaveKey('unit_price_gross')
        ->and($result['payload']['items'][0]['vat_type'])->toBe('5')
        ->and($result['payload']['buyer']['tax_type'])->toBe('none')
        ->and($result['payload']['buyer'])->not->toHaveKey('tax_number');
});

it('maps foreign B2B and non-EU lines by goods/service, shipping follows the goods', function (string $country, string $taxId, string $goods, string $services) {
    $order = order(buyer($country, $taxId), [
        product('1', 'Widget', 1, 100.0, 100.0, 0.0),
        product('2', 'Consulting', 1, 100.0, 100.0, 0.0, true),
        shipping('3', 20.0, 20.0, 0.0),
    ]);

    $items = (new OrderPayloadBuilder(settings()))->build($order)['payload']['items'];

    expect($items[0]['vat_type'])->toBe($goods)->and($items[0]['units'])->toBe('szt.')
        ->and($items[1]['vat_type'])->toBe($services)->and($items[1]['units'])->toBe('usł.')
        ->and($items[2]['vat_type'])->toBe($goods);
})->with([
    ['DE', 'DE123456789', '0 WDT', 'np I'],
    ['US', '12-3456789', '0 EX', 'np II'],
    ['US', '', '0 EX', 'np II'],
]);

it('gives a services-only order a service shipping line', function () {
    $order = order(buyer('DE', 'DE123456789'), [
        product('1', 'Consulting', 1, 100.0, 100.0, 0.0, true),
        shipping('3', 20.0, 20.0, 0.0),
    ]);

    expect((new OrderPayloadBuilder(settings()))->build($order)['payload']['items'][1]['vat_type'])->toBe('np I');
});

it('spreads a negative fee over product lines and keeps the totals', function () {
    $order = order(buyer('PL'), [
        product('1', 'A', 2, 100.0, 123.0, 23.0),
        product('2', 'B', 1, 100.0, 123.0, 23.0),
        shipping('3', 10.0, 12.3),
        fee('4', 'Rabat', -30.0, -36.9),
    ]);

    $result = (new OrderPayloadBuilder(settings()))->build($order);
    $items = $result['payload']['items'];

    expect($result['hasNegativeLines'])->toBeTrue()
        ->and($items)->toHaveCount(3)
        ->and($items[0])->toMatchArray(['unit_price' => 42.5, 'unit_price_gross' => 52.275, 'name' => 'A (z rabatem)'])
        ->and($items[1])->toMatchArray(['unit_price' => 85.0, 'unit_price_gross' => 104.55])
        ->and($items[2]['unit_price'])->toBe(10.0)
        ->and($result['lineMap'])->toBe(['1' => 1, '2' => 2, '3' => 3]);

    $grossSum = 0.0;
    foreach ($items as $item) {
        $grossSum += round($item['unit_price_gross'] * $item['quantity'], 2);
    }
    expect($grossSum)->toBe(221.4); // 123 + 123 + 12.3 - 36.9

    $skipped = (new OrderPayloadBuilder(settings(['negativeLines' => Settings::NEGATIVE_SKIP])))->build($order);
    expect($skipped['hasNegativeLines'])->toBeTrue()->and($skipped['payload']['items'])->toHaveCount(4);
});

it('skips zero-quantity products and free shipping', function () {
    $order = order(buyer('PL'), [product('1', 'Gratis', 0, 0.0, 0.0, 23.0), shipping('2', 0.0, 0.0)]);

    expect((new OrderPayloadBuilder(settings()))->build($order)['payload']['items'])->toBe([]);
});

it('uses domestic rates for an EU company that failed VIES in domestic mode', function () {
    // The shop applied 0% (reverse charge configured); only the failed VIES check moves it to Polish rates.
    $order = order(buyer('DE', 'DE123456789'), [product('1', 'Widget', 1, 100.0, 100.0, 0.0)]);

    $domestic = (new OrderPayloadBuilder(settings(['viesCheck' => Settings::VIES_DOMESTIC])))->build($order, 'invalid');
    $block = (new OrderPayloadBuilder(settings(['viesCheck' => Settings::VIES_BLOCK])))->build($order, 'invalid');

    expect($domestic['scenario'])->toBe(BuyerScenario::EU_B2B_DOMESTIC)->and($domestic['payload']['items'][0]['vat_type'])->toBe('23')
        ->and($domestic['warnings'])->toBe([Settings::WARNING_VIES_INVALID_DOMESTIC])
        ->and($block['payload']['items'][0]['vat_type'])->toBe('0 WDT')
        ->and($block['warnings'])->toBe([]);
});

it('maps domestic rates and unknown percentages', function () {
    $settings = settings(['vatTypeForZeroRate' => 'zw', 'vatTypeForNoTax' => 'np I']);

    expect(VatMapper::domestic(23.0, $settings))->toBe('23')
        ->and(VatMapper::domestic(7.9, $settings))->toBe('8')
        ->and(VatMapper::domestic(0.0, $settings))->toBe('zw')
        ->and(VatMapper::domestic(null, $settings))->toBe('np I')
        ->and(VatMapper::domestic(19.0, $settings))->toBe('23')
        ->and(VatMapper::domestic(10.0, $settings))->toBe('8')
        ->and(VatMapper::domestic(2.0, $settings))->toBe('5');

    $settings->unknownRateMapper = static function (float $percent): ?string {
        return $percent === 19.0 ? 'zw' : null;
    };
    expect(VatMapper::domestic(19.0, $settings))->toBe('zw')->and(VatMapper::domestic(10.0, $settings))->toBe('8');
});

it('derives the OSS rate from the order taxes', function () {
    $de = fn (?float $a, ?float $b) => order(buyer('DE'), [product('1', 'W', 1, 100.0, 119.0, $a), shipping('2', 10.0, 11.9, $b)]);

    expect(VatMapper::ossRate($de(19.0, 19.0)))->toBe('19')
        ->and(VatMapper::ossRate($de(13.5, 13.5)))->toBe('13.5')
        ->and(VatMapper::ossRate($de(19.0, 7.0)))->toBeNull()
        ->and(VatMapper::ossRate($de(0.0, 0.0)))->toBeNull()
        ->and(VatMapper::ossRate($de(null, null)))->toBeNull();
});

it('builds the mark-paid body per gateway and scenario', function () {
    $pl = order(buyer('PL', '5261040828'), [product('1', 'W', 1, 100.0, 123.0, 23.0)], 'cod');
    expect(MarkPaidPayload::build($pl, settings(['billtoSendsEmail' => false, 'seriesId' => 'ser-1'])))
        ->toBe(['send_email' => false, 'mark_paid' => false, 'series_id' => 'ser-1']);

    $de = order(buyer('DE'), [product('1', 'W', 1, 100.0, 119.0, 19.0)], 'cheque');
    expect(MarkPaidPayload::build($de, settings(['ossMode' => Settings::OSS_INVOICE, 'ossSeriesId' => 'oss-1', 'seriesId' => 'ser-1'])))
        ->toBe(['send_email' => true, 'invoice_type' => 'oss', 'oss_vat_type' => '19', 'series_id' => 'oss-1']);
    expect(MarkPaidPayload::build($de, settings(['ossMode' => Settings::OSS_PL_VAT, 'seriesId' => 'ser-1'])))
        ->toBe(['send_email' => true, 'series_id' => 'ser-1']);

    expect(MarkPaidPayload::issueKor(settings(['korSeriesId' => 'kor-1', 'billtoSendsEmail' => false])))
        ->toBe(['send_email' => false, 'series_id' => 'kor-1']);
});
