<?php

namespace App\Services\Adaptive\Canvas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Canvas REST API client (https://canvas.instructure.com/doc/api/).
 *
 * - Sends the caller's token as a Bearer header; tokens are never logged or put in URLs.
 * - Follows pagination through the Link header, but only to the configured Canvas host,
 *   so a token is never sent anywhere else.
 * - 401 → CanvasNotConnected (token invalid or expired), 403/404 → null (no access),
 *   other failures → CanvasUnavailable.
 */
class CanvasClient
{
    private const PER_PAGE = 100;

    private const MAX_PAGES = 50;

    public function baseUrl(): string
    {
        $base = rtrim((string) config('adaptive.canvas.base_url'), '/');
        if ($base === '' || ! preg_match('#^https?://[^/]+$#i', $base)) {
            throw new CanvasUnavailable('Canvas is not configured. Set CANVAS_BASE_URL, e.g. https://school.instructure.com');
        }

        return $base;
    }

    /**
     * GET one object. Returns null when Canvas says the user has no access (403/404).
     *
     * @param  array<string, scalar|list<scalar>>  $query
     * @return array<string, mixed>|null
     */
    public function getObject(string $token, string $path, array $query = []): ?array
    {
        $response = $this->send($token, $this->baseUrl().$path.$this->queryString($query));

        return $response === null ? null : (array) $response->json();
    }

    /**
     * GET every page of a list. Returns [] when Canvas says the user has no access.
     *
     * @param  array<string, scalar|list<scalar>>  $query
     * @return list<array<string, mixed>>
     */
    public function getList(string $token, string $path, array $query = []): array
    {
        $url = $this->baseUrl().$path.$this->queryString($query + ['per_page' => self::PER_PAGE]);
        $items = [];

        for ($page = 0; $url !== null && $page < self::MAX_PAGES; $page++) {
            $response = $this->send($token, $url);
            if ($response === null) {
                return [];
            }
            foreach ((array) $response->json() as $item) {
                $items[] = (array) $item;
            }
            $url = $this->nextPage($response);
        }

        return $items;
    }

    private function send(string $token, string $url): ?Response
    {
        try {
            $response = Http::withToken($token)->acceptJson()
                ->timeout((int) config('adaptive.canvas.timeout_seconds', 15))
                ->get($url);
        } catch (ConnectionException) {
            throw new CanvasUnavailable('Canvas could not be reached. Try again later.');
        } catch (RequestException $e) {
            // Some client configurations throw for 4xx/5xx instead of returning the response.
            $response = $e->response;
        }

        return match (true) {
            $response->successful() => $response,
            $response->status() === 401 => throw new CanvasNotConnected('Your Canvas access has expired or was revoked. Reconnect Canvas.'),
            in_array($response->status(), [403, 404], true) => null,
            default => throw new CanvasUnavailable("Canvas returned an error ({$response->status()}). Try again later."),
        };
    }

    /** Canvas pagination: Link: <https://…?page=2>; rel="next". Only the configured host is followed. */
    private function nextPage(Response $response): ?string
    {
        foreach (explode(',', (string) $response->header('Link')) as $part) {
            if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $part, $m)) {
                $next = $m[1];
                $base = $this->baseUrl();

                return str_starts_with($next, $base.'/') ? $next : null;
            }
        }

        return null;
    }

    /**
     * Canvas (Rails) expects arrays as key[]=a&key[]=b, which http_build_query does not produce.
     *
     * @param  array<string, scalar|list<scalar>>  $query
     */
    private function queryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = rawurlencode($key).(is_array($value) ? '[]' : '').'='.rawurlencode((string) $v);
            }
        }

        return $parts === [] ? '' : '?'.implode('&', $parts);
    }
}
