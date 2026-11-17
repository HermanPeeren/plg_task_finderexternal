<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Http\AddressGuard;
use Yepr\Plugin\Task\FinderExternal\Http\HttpFailure;
use Yepr\Plugin\Task\FinderExternal\Http\JoomlaHttpClient;

final class AddressGuardTest extends TestCase
{
    /**
     * @param   array<string, list<string>>  $dns
     */
    private function guard(array $dns): AddressGuard
    {
        return new AddressGuard(static fn (string $host): array => $dns[$host] ?? []);
    }

    public function testAPublicHost(): void
    {
        $guard = $this->guard(['www.example.org' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]);

        self::assertSame(['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'], $guard->addresses('www.example.org'));
        self::assertSame(['8.8.8.8'], $guard->addresses('8.8.8.8'));
    }

    /**
     * @return  iterable<string, array{string, list<string>}>
     */
    public static function inward(): iterable
    {
        yield 'loopback' => ['localhost', ['127.0.0.1']];
        yield 'private network' => ['intranet.example.org', ['10.1.2.3']];
        yield 'one of several addresses' => ['mixed.example.org', ['93.184.215.14', '192.168.1.10']];
        yield 'cloud metadata' => ['metadata.example.org', ['169.254.169.254']];
        yield 'IPv6 loopback' => ['[::1]', []];
        yield 'IPv6 unique local' => ['v6.example.org', ['fd00::1']];
        yield 'unspecified' => ['0.0.0.0', []];
        yield 'does not resolve' => ['nowhere.example.org', []];
    }

    /**
     * @param   list<string>  $addresses
     */
    #[DataProvider('inward')]
    public function testPrivateReservedAndUnknownHostsAreRefused(string $host, array $addresses): void
    {
        $this->expectException(HttpFailure::class);

        $this->guard([trim($host, '[]') => $addresses])->addresses($host);
    }

    public function testTheClientRefusesOtherSchemesBeforeAnyRequest(): void
    {
        $client = new JoomlaHttpClient($this->guard([]));

        $this->expectException(HttpFailure::class);
        $this->expectExceptionMessage('is not an http(s) address');

        $client->get('file:///etc/passwd', [], 5);
    }

    public function testTheClientRefusesAPrivateHostBeforeAnyRequest(): void
    {
        $client = new JoomlaHttpClient($this->guard(['intranet.example.org' => ['10.0.0.5']]));

        $this->expectException(HttpFailure::class);
        $this->expectExceptionMessage('private or reserved address');

        $client->get('https://intranet.example.org/', [], 5);
    }
}
