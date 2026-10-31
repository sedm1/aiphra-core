<?php

namespace AiphraCore\Providers\GoogleCalendar\Internal\Api;

use AiphraCore\Exceptions\ProviderResponseException;
use AiphraCore\Http\HttpClient;
use AiphraCore\Objects\Calendars\Calendar;

/**
 * Загружает следующую страницу Google только тогда, когда потребитель продолжает чтение.
 * @internal
 */
final readonly class ReadCalendarList {
    public function __construct(private HttpClient $http, private string $url) {}

    /**
     * @param positive-int $pageSize
     * @return iterable<Calendar>
     */
    public function read(int $pageSize): iterable {
        $pageToken = null;
        $seenTokens = [];
        do {
            $query = ['maxResults' => $pageSize];
            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }
            $url = $this->url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $data = $this->http->request('GET', $url)->json();
            $items = array_key_exists('items', $data) ? $data['items'] : [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new ProviderResponseException('Google returned an invalid calendar list.');
            }
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || !$item['id']) {
                    throw new ProviderResponseException('Google returned invalid calendar metadata.');
                }
                $name = array_key_exists('summary', $item) ? $item['summary'] : '';
                if (!is_string($name)) {
                    throw new ProviderResponseException('Google returned invalid calendar metadata.');
                }
                yield new Calendar($item['id'], $name);
            }
            $pageToken = $data['nextPageToken'] ?? null;
            if ($pageToken !== null && (!is_string($pageToken) || isset($seenTokens[$pageToken]))) {
                throw new ProviderResponseException('Google returned an invalid or repeated page token.');
            }
            if ($pageToken) {
                $seenTokens[$pageToken] = true;
            }
        } while ($pageToken);
    }
}
