<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Support;

use Yepr\Plugin\Task\FinderExternal\Http\HttpClient;
use Yepr\Plugin\Task\FinderExternal\Http\HttpFailure;
use Yepr\Plugin\Task\FinderExternal\Http\HttpResponse;

/**
 * A web of pages in memory. Unknown URLs answer 404. Every request costs time on the
 * fake clock, so time budgets can be tested.
 */
final class FakeHttpClient implements HttpClient
{
    /**
     * @var array<string, HttpResponse|HttpFailure>
     */
    private array $pages = [];

    /**
     * @var list<array{url: string, headers: array<string, string>}>
     */
    public array $requests = [];

    public function __construct(private readonly FakeClock $clock, private readonly float $secondsPerRequest = 0.1)
    {
    }

    /**
     * @param   array<string, string>  $headers
     */
    public function page(string $url, string $body, int $status = 200, array $headers = ['content-type' => 'text/html; charset=utf-8']): self
    {
        $this->pages[$url] = new HttpResponse($status, $headers, $body);

        return $this;
    }

    public function redirect(string $url, string $location, int $status = 301): self
    {
        return $this->page($url, '', $status, ['location' => $location]);
    }

    public function fail(string $url, string $message): self
    {
        $this->pages[$url] = new HttpFailure($message);

        return $this;
    }

    public function forget(string $url): self
    {
        unset($this->pages[$url]);

        return $this;
    }

    public function get(string $url, array $headers, int $timeout): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        $this->clock->advance($this->secondsPerRequest);

        $page = $this->pages[$url] ?? new HttpResponse(404, ['content-type' => 'text/html'], 'Not found');

        if ($page instanceof HttpFailure) {
            throw $page;
        }

        $etag = $page->header('etag');

        if ($etag !== '' && ($headers['If-None-Match'] ?? '') === $etag) {
            return new HttpResponse(304, ['etag' => $etag], '');
        }

        return $page;
    }

    /**
     * @return  list<string>
     */
    public function requestedUrls(): array
    {
        return array_column($this->requests, 'url');
    }
}
