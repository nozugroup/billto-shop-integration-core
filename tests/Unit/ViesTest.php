<?php

declare(strict_types=1);

use BillTo\Shop\KsefStatus;
use BillTo\Shop\Nip;
use BillTo\Shop\Vies\HttpGetInterface;
use BillTo\Shop\Vies\Vies;

final class FakeHttp implements HttpGetInterface
{
    /** @var list<string> */
    public array $urls = [];

    public function __construct(private ?array $response) {}

    public function get(string $url): ?array
    {
        $this->urls[] = $url;

        return $this->response;
    }
}

it('validates NIP checksums and normalises EU ids', function () {
    expect(Nip::isValid('526-104-08-28'))->toBeTrue()
        ->and(Nip::isValid('5261040829'))->toBeFalse()
        ->and(Nip::normalize('PL 526-104-08-28'))->toBe('5261040828')
        ->and(Nip::normalizeEuVat('de 123 456 789'))->toBe('DE123456789')
        ->and(Nip::normalizeEuVat('123'))->toBeNull()
        ->and(Nip::euVatFor('123456789', 'DE'))->toBe('DE123456789');
});

it('rejects malformed ids without calling the service', function () {
    $http = new FakeHttp(['status' => 200, 'body' => '{"isValid":true}']);

    expect(Vies::check('12345', $http)['status'])->toBe(Vies::INVALID)->and($http->urls)->toBe([]);
});

it('interprets VIES responses', function (array $body, int $status, string $expected) {
    $http = new FakeHttp(['status' => $status, 'body' => json_encode($body)]);

    expect(Vies::check('DE123456789', $http)['status'])->toBe($expected);
})->with([
    'valid' => [['isValid' => true, 'name' => 'Beispiel GmbH'], 200, Vies::VALID],
    'invalid' => [['isValid' => false, 'userError' => 'INVALID'], 200, Vies::INVALID],
    'member state down' => [['isValid' => false, 'userError' => 'MS_UNAVAILABLE'], 200, Vies::UNAVAILABLE],
    'http 503' => [[], 503, Vies::UNAVAILABLE],
]);

it('reports transport failures as unavailable and maps Greece to EL', function () {
    expect(Vies::check('DE123456789', new FakeHttp(null))['status'])->toBe(Vies::UNAVAILABLE);

    $http = new FakeHttp(['status' => 200, 'body' => '{"isValid":true}']);
    Vies::check('GR123456789', $http);
    expect($http->urls[0])->toContain('/ms/EL/vat/123456789');
});

it('interprets KSeF status data', function () {
    expect(KsefStatus::of(['sent' => true, 'ksef_number' => 'K-1']))->toBe(KsefStatus::ASSIGNED)
        ->and(KsefStatus::of(['sent' => true, 'ksef_number' => null, 'has_unresolved_errors' => true, 'errors' => [['status_description' => 'Bad XML']]]))->toBe(KsefStatus::ERROR)
        ->and(KsefStatus::firstError(['errors' => [['status_description' => 'Bad XML']]]))->toBe('Bad XML')
        ->and(KsefStatus::of(['sent' => true]))->toBe(KsefStatus::PENDING)
        ->and(KsefStatus::of([]))->toBe(KsefStatus::NONE);
});
