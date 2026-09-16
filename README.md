# billto/shop-integration-core

Platform-independent business logic shared by the BillTo e-commerce plugins
([WooCommerce](https://github.com/nozugroup/billto-woocommerce), PrestaShop). **PHP 7.2+, no runtime
dependencies**, so it can be bundled inside a plugin ZIP without Composer on the merchant's host.

It is deliberately *not* the [BillTo PHP SDK](https://github.com/nozugroup/billto-php): the SDK is an
HTTP client for PHP 8.2+; this package decides **what** to send and leaves the HTTP layer to the plugin.

## What it decides

| Class | Responsibility |
|---|---|
| `Model\Order`, `Model\Line`, `Model\Buyer` | the shop order in a platform-neutral shape (each plugin maps its own objects onto it) |
| `Settings` | integration settings with safe defaults (amount mode, OSS mode, VIES, negative lines, unpaid gateways, series) |
| `BuyerScenario` | PL consumer / PL company / EU consumer / EU company / outside EU; tax identity for the BillTo buyer; scenario switches |
| `VatMapper` | shop tax percentage -> BillTo `vat_type`; `0 WDT` / `np I` / `0 EX` / `np II` for foreign buyers; OSS rate |
| `NegativeLines` | spreads discount fees / gift cards over product lines, last line absorbs rounding |
| `OrderPayloadBuilder` | body of `POST /orders` (net or gross mode) + line map for later corrections |
| `MarkPaidPayload` | body of `POST /orders/{id}/mark-paid` (`send_email`, `mark_paid`, `series_id`, `invoice_type=oss`) and of `issue-kor` |
| `RefundLines` | `issue-correction` lines: remaining quantities (cumulative) or proportional price reduction |
| `Vies\Vies` | EU VAT id check against the Commission's REST API through a tiny `HttpGetInterface` the plugin implements |
| `KsefStatus`, `ApiPaths`, `Nip`, `EuCountries` | helpers |

## Usage

```php
use BillTo\Shop\Model\{Order, Line, Buyer};
use BillTo\Shop\{Settings, OrderPayloadBuilder, MarkPaidPayload};

$settings = new Settings;
$settings->source = 'prestashop';
$settings->amountMode = Settings::AMOUNT_GROSS;
$settings->unpaidGateways = ['ps_cashondelivery'];

$order = new Order('1042', 'ABCDEFGHI', 'PLN', '2026-09-14',
    new Buyer('ACME sp. z o.o.', 'PL', '5261040828', 'Testowa 5', '30-001 Kraków', 'jan@acme.pl'),
    [
        new Line('7', Line::TYPE_PRODUCT, 'Kubek', 2.0, 80.00, 98.40, 23.0),
        new Line('s', Line::TYPE_SHIPPING, 'Kurier', 1.0, 9.92, 12.20, 23.0),
    ],
    'ps_cashondelivery', 'Za pobraniem'
);

$built = (new OrderPayloadBuilder($settings))->build($order);   // POST /orders body + lineMap
$markPaid = MarkPaidPayload::build($order, $settings);           // ['send_email' => true, 'mark_paid' => false]
```

Store `$built['lineMap']` (platform line id => BillTo `line_number`) with the order; `RefundLines` needs it later.

## Development

```bash
composer install
composer check   # PHPCompatibility 7.2-, PHPStan, Pest
```

The source must stay PHP 7.2 compatible (no typed properties, arrow functions, `match`, union types,
`str_contains`...). CI runs `php -l` on 7.2/7.4/8.0 and PHPCompatibility on every push.

## License

MIT.
