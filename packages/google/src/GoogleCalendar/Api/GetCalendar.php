<?php

namespace AiphraCore\Providers\GoogleCalendar\Api;

use AiphraCore\Exceptions\{ProviderResponseException, ResourceNotFoundException, ValidationException};
use AiphraCore\Objects\Calendars\Calendar;

/**
 * Получение календаря через Google REST API.
 */
trait GetCalendar {
    protected function getCalendarViaApi(): Calendar {
        foreach ($this->filters as $filter) {
            if ($filter->name !== 'id') { continue; }
            $url = self::API_URL . 'calendars/' . rawurlencode($filter->values[0]);
            $data = $this->client->request('GET', $url)->json();
            $name = array_key_exists('summary', $data) ? $data['summary'] : '';
            if (!is_string($data['id'] ?? null) || !$data['id'] || !is_string($name)) {
                throw new ProviderResponseException('Google returned invalid calendar metadata.');
            }
            $calendar = new Calendar($data['id'], $name);
            if (!$this->matches($calendar)) {
                throw new ResourceNotFoundException('Calendar does not match the filters.');
            }
            return $calendar;
        }
        throw new ValidationException('A single calendar requires an id Equals filter.');
    }
}
