<?php

declare(strict_types=1);

use BillTo\Shop\OAuth\HttpPostInterface;
use BillTo\Shop\OAuth\OAuthClient;
use BillTo\Shop\OAuth\Pkce;
use BillTo\Shop\OAuth\TokenSet;

/** Records what the client sent and replays a canned response. */
final class FakePost implements HttpPostInterface
{
    /** @var array{status: int, body: string}|null */
    private $response;

    /** @var array<int, array{url: string, fields: array<string, string>}> */
    public $calls = [];

    public function __construct(?array $response)
    {
        $this->response = $response;
    }

    public function post(string $url, array $fields, array $headers = []): ?array
    {
        $this->calls[] = ['url' => $url, 'fields' => $fields];

        return $this->response;
    }
}

// ── PKCE ─────────────────────────────────────────────────────────────────────

it('generates a verifier within the length the spec allows', function () {
    $verifier = Pkce::verifier();

    expect(strlen($verifier))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128)
        ->and($verifier)->toMatch('/^[A-Za-z0-9\-_]+$/');
});

it('derives a stable S256 challenge and never repeats a verifier', function () {
    $verifier = Pkce::verifier();

    expect(Pkce::challenge($verifier))->toBe(Pkce::challenge($verifier))
        ->and(Pkce::verifier())->not->toBe($verifier)
        ->and(Pkce::state())->not->toBe(Pkce::state());
});

// ── Authorization URL ────────────────────────────────────────────────────────

it('builds an authorization url carrying the PKCE challenge, not the verifier', function () {
    // The verifier must never leave the server - that is the whole point of the exchange.
    $verifier = Pkce::verifier();

    $url = (new OAuthClient('https://billto.pl/', new FakePost(null)))->authorizationUrl(
        'client-123',
        'https://sklep.example/billto/callback',
        ['invoices:read', 'invoices:write'],
        'state-abc',
        $verifier
    );

    expect($url)->toStartWith('https://billto.pl/oauth/authorize?')
        ->and($url)->toContain('code_challenge='.Pkce::challenge($verifier))
        ->and($url)->toContain('code_challenge_method=S256')
        ->and($url)->toContain('scope=invoices%3Aread+invoices%3Awrite')
        ->and($url)->not->toContain($verifier);
});

// ── Registration (DCR - last resort) ─────────────────────────────────────────

it('registers an installation with the vendor id and the merchant code', function () {
    $http = new FakePost(['status' => 201, 'body' => json_encode([
        'client_id' => 'cid', 'client_secret' => 'secret',
    ])]);

    $credentials = (new OAuthClient('https://billto.pl', $http))
        ->register('3b8cacc7-eeec', 'blti_ABC', 'sklep.example 12345 16-09-2026', 'https://sklep.example/callback');

    // Neither half works alone: the id says who is registering, the code says whose data.
    expect($credentials)->toBe(['client_id' => 'cid', 'client_secret' => 'secret'])
        ->and($http->calls[0]['url'])->toBe('https://billto.pl/oauth/register')
        ->and($http->calls[0]['fields']['software_statement_id'])->toBe('3b8cacc7-eeec')
        ->and($http->calls[0]['fields']['code'])->toBe('blti_ABC')
        ->and($http->calls[0]['fields']['client_name'])->toBe('sklep.example 12345 16-09-2026');
});

it('reports a refused or malformed registration as failure instead of half-connecting', function (?array $response) {
    $credentials = (new OAuthClient('https://billto.pl', new FakePost($response)))
        ->register('3b8cacc7-eeec', 'blti_ABC', 'Sklep', 'https://sklep.example/callback');

    expect($credentials)->toBeNull();
})->with([
    // Każdy wpis opakowany w dodatkową tablicę: Pest rozpakowuje zestaw danych na ARGUMENTY,
    // więc goła ['status' => …, 'body' => …] trafiłaby jako dwa parametry zamiast jednego.
    'transport failure' => [null],
    'bad code' => [['status' => 403, 'body' => '{"error":"invalid_code"}']],
    'duplicate name' => [['status' => 422, 'body' => '{"error":"invalid_client_metadata"}']],
    'no secret in body' => [['status' => 201, 'body' => '{"client_id":"cid"}']],
    'not json' => [['status' => 201, 'body' => '<html>502</html>']],
]);

// ── Token exchange ───────────────────────────────────────────────────────────

it('exchanges the code and sends the verifier', function () {
    $http = new FakePost(['status' => 200, 'body' => json_encode([
        'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 43200,
    ])]);

    $tokens = (new OAuthClient('https://billto.pl', $http))
        ->exchangeCode('cid', 'secret', 'https://sklep.example/callback', 'the-code', 'the-verifier');

    expect($tokens)->toBe(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 43200])
        ->and($http->calls[0]['url'])->toBe('https://billto.pl/oauth/token')
        ->and($http->calls[0]['fields']['grant_type'])->toBe('authorization_code')
        ->and($http->calls[0]['fields']['code_verifier'])->toBe('the-verifier');
});

it('treats a missing expires_in as already expired rather than assuming a long life', function () {
    // An unexpected response shape must not leave the plugin using a dead token for hours.
    $http = new FakePost(['status' => 200, 'body' => '{"access_token":"at"}']);

    $tokens = (new OAuthClient('https://billto.pl', $http))
        ->refresh('cid', 'secret', 'rt');

    expect($tokens['expires_in'])->toBe(0)
        ->and($tokens['refresh_token'])->toBeNull();
});

it('reports a rejected refresh as failure', function () {
    $tokens = (new OAuthClient('https://billto.pl', new FakePost(['status' => 400, 'body' => '{"error":"invalid_grant"}'])))
        ->refresh('cid', 'secret', 'rt');

    expect($tokens)->toBeNull();
});

// ── TokenSet ─────────────────────────────────────────────────────────────────

it('refreshes before the declared expiry, not after it', function () {
    // Refreshing only after a 401 costs one failed request per expiry - and if that request
    // was an invoice being issued, the shop already told the customer it failed.
    $set = TokenSet::fromTokenResponse(
        ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600],
        1_000_000
    );

    expect($set->expiresAt)->toBe(1_003_600)
        ->and($set->needsRefresh(1_000_000))->toBeFalse()
        ->and($set->needsRefresh(1_003_290))->toBeFalse()
        ->and($set->needsRefresh(1_003_301))->toBeTrue()
        ->and($set->needsRefresh(1_003_600))->toBeTrue();
});

it('knows when the merchant has to authorise the shop again by hand', function () {
    $withRefresh = new TokenSet('at', 'rt', time() + 60);
    $without = new TokenSet('at', null, time() + 60);

    expect($withRefresh->canRefresh())->toBeTrue()
        ->and($without->canRefresh())->toBeFalse();
});

it('round-trips through storage', function () {
    $set = new TokenSet('at', 'rt', 1_700_000_000);
    $restored = TokenSet::fromArray($set->toArray());

    expect($restored->accessToken)->toBe('at')
        ->and($restored->refreshToken)->toBe('rt')
        ->and($restored->expiresAt)->toBe(1_700_000_000);
});

it('refuses to restore an empty or damaged stored value', function (array $stored) {
    expect(TokenSet::fromArray($stored))->toBeNull();
})->with([
    'empty' => [[]],
    'blank token' => [['access_token' => '']],
    'wrong type' => [['access_token' => ['nested']]],
]);




