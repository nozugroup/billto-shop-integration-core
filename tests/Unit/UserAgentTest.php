<?php

declare(strict_types=1);

use BillTo\Shop\UserAgent;

it('builds the full header the BillTo API expects', function () {
    $header = UserAgent::build(
        'billto-woocommerce',
        '1.4.2',
        'https://billto.pl/integracje/woocommerce',
        '2026-09-15',
        ['WordPress/6.5', 'WooCommerce/8.9']
    );

    expect($header)->toBe(
        'billto-woocommerce/1.4.2 (+https://billto.pl/integracje/woocommerce; compat=2026-09-15) WordPress/6.5 WooCommerce/8.9'
    );
});

it('omits compat when the integration declares none', function () {
    expect(UserAgent::build('billto-prestashop', '1.0.0', 'dev@example.test'))
        ->toBe('billto-prestashop/1.0.0 (+dev@example.test)');
});

it('keeps an e-mail contact intact', function () {
    expect(UserAgent::build('shop', '1.0', 'integracje@sklep.example'))
        ->toContain('(+integracje@sklep.example)');
});

it('strips characters that would break the header structure', function () {
    // A shop can put almost anything in its version string - hosting providers routinely patch
    // plugin headers. An unescaped ")" would end the metadata group early and the parser on the
    // other side would read the rest as diagnostic tokens.
    $header = UserAgent::build('bad)product', '1.0 (beta)', 'https://example.test/a)b', 'v1;2');

    expect($header)->toBe('badproduct/1.0beta (+https://example.test/ab; compat=v12)')
        ->and(substr_count($header, '('))->toBe(1)
        ->and(substr_count($header, ')'))->toBe(1);
});

it('drops empty extra tokens instead of leaving double spaces', function () {
    expect(UserAgent::build('shop', '1.0', 'dev@example.test', null, ['', 'PHP/8.2', '   ']))
        ->toBe('shop/1.0 (+dev@example.test) PHP/8.2');
});

it('caps absurdly long input', function () {
    $header = UserAgent::build(str_repeat('a', 500), str_repeat('1', 500), str_repeat('b', 500));

    expect(strlen($header))->toBeLessThan(320);
});
