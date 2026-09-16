<?php

declare(strict_types=1);

use BillTo\Shop\BuyerScenario;
use BillTo\Shop\Settings;
use BillTo\Shop\Vies\Vies;

it('classifies buyers by country and tax id', function (string $country, bool $taxId, string $expected) {
    expect(BuyerScenario::classify($country, $taxId))->toBe($expected);
})->with([
    ['PL', false, BuyerScenario::PL_B2C],
    ['pl', true, BuyerScenario::PL_B2B],
    ['', false, BuyerScenario::PL_B2C],
    ['DE', false, BuyerScenario::EU_B2C],
    ['DE', true, BuyerScenario::EU_B2B],
    ['US', false, BuyerScenario::NON_EU],
    ['GB', true, BuyerScenario::NON_EU],
]);

it('falls back to domestic rates for an EU company that failed VIES only when configured', function () {
    $settings = new Settings;
    $settings->viesCheck = Settings::VIES_DOMESTIC;
    expect(BuyerScenario::effective(buyer('DE', 'DE123456789'), $settings, Vies::INVALID))->toBe(BuyerScenario::EU_B2B_DOMESTIC)
        ->and(BuyerScenario::effective(buyer('DE', 'DE123456789'), $settings, Vies::VALID))->toBe(BuyerScenario::EU_B2B);

    $settings->viesCheck = Settings::VIES_BLOCK;
    expect(BuyerScenario::effective(buyer('DE', 'DE123456789'), $settings, Vies::INVALID))->toBe(BuyerScenario::EU_B2B);
});

it('honours the scenario switches', function () {
    $settings = new Settings;
    $settings->ossMode = Settings::OSS_OFF;
    $settings->euB2bEnabled = false;

    expect(BuyerScenario::isEnabled(BuyerScenario::EU_B2C, $settings))->toBeFalse()
        ->and(BuyerScenario::isEnabled(BuyerScenario::EU_B2B, $settings))->toBeFalse()
        ->and(BuyerScenario::isEnabled(BuyerScenario::NON_EU, $settings))->toBeTrue()
        ->and(BuyerScenario::isEnabled(BuyerScenario::PL_B2C, $settings))->toBeTrue();
});

it('derives the BillTo tax identity', function () {
    expect(BuyerScenario::taxIdentity(buyer('PL', '526-104-08-28')))->toBe(['local', '5261040828', null])
        ->and(BuyerScenario::taxIdentity(buyer('PL')))->toBe(['none', null, null])
        ->and(BuyerScenario::taxIdentity(buyer('DE', '123456789')))->toBe(['eu', 'DE123456789', 'DE'])
        ->and(BuyerScenario::taxIdentity(buyer('DE', 'de 123 456 789')))->toBe(['eu', 'DE123456789', 'DE'])
        ->and(BuyerScenario::taxIdentity(buyer('US', '12-3456789')))->toBe(['noneu', '12-3456789', 'US']);
});
