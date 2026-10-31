<?php

declare(strict_types=1);

use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Http\Response;
use AiphraCore\Objects\Calendars\Calendar;
use AiphraCore\Method;
use AiphraCore\Operation;
use AiphraCore\Providers\GoogleCalendar;

$tests = [];

function googlePagingProvider(FakeHttp $http): GoogleCalendar\Provider {
    return new GoogleCalendar\Provider(new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http));
}

$tests['Google API limit zero skips OAuth and list requests'] = static function (): void {
    $http = new FakeHttp();
    same(0, count(googlePagingProvider($http)->calendars(Operation::Get)->setMethod(Method::Api)->limit(0)->exec()));
    same([], $http->requests);
};

$tests['Google API requests only the needed small page and stops at its limit'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=3', new Response(200,
            '{"items":[{"id":"skip"},{"id":"one"},{"id":"two"}],"nextPageToken":"unused"}'));
    $result = googlePagingProvider($http)->calendars(Operation::Get)->setMethod(Method::Api)->offset(1)->limit(2)->exec();
    same(['one', 'two'], array_map(fn (Calendar $calendar): string => $calendar->getId(), $result->all()));
    $http->done();
};

$tests['Google API counts only matching calendars and stops before an unused page'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200,
            '{"items":[{"id":"other","summary":"Other"},{"id":"one","summary":"Wanted A"}],"nextPageToken":"next page"}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250&pageToken=next%20page', new Response(200,
            '{"items":[{"id":"two","summary":"Wanted B"}],"nextPageToken":"unused"}'));
    $result = googlePagingProvider($http)->calendars(Operation::Get)->setMethod(Method::Api)
        ->addFilter(Field::genFilter('name', Operator::Contains, ['Wanted']))->offset(1)->limit(1)->exec();
    same('two', $result->all()[0]->getId());
    $http->done();
};

$tests['Google API crosses large offsets using pages no larger than 250'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200,
            json_encode(['items' => array_map(fn (int $id): array => ['id' => 'item-' . $id], range(0, 249)), 'nextPageToken' => 'next'], JSON_THROW_ON_ERROR)))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250&pageToken=next', new Response(200,
            json_encode(['items' => array_map(fn (int $id): array => ['id' => 'item-' . $id], range(250, 269)), 'nextPageToken' => 'unused'], JSON_THROW_ON_ERROR)));
    $result = googlePagingProvider($http)->calendars(Operation::Get)->setMethod(Method::Api)->offset(260)->limit(2)->exec();
    same(['item-260', 'item-261'], array_map(fn (Calendar $calendar): string => $calendar->getId(), $result->all()));
    $http->done();
};

$tests['Google API follows a next token even when the current page is empty'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=1', new Response(200, '{"items":[],"nextPageToken":"next"}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=1&pageToken=next', new Response(200, '{"items":[{"id":"one"}]}'));
    same('one', googlePagingProvider($http)->calendars(Operation::Get)->setMethod(Method::Api)->limit(1)->exec()->all()[0]->getId());
    $http->done();
};

$tests['Google API handles maximum integer offsets and limits without overflow'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200, '{"items":[{"id":"one"}]}'));
    same(0, count(googlePagingProvider($http)->calendars(Operation::Get)->setMethod(Method::Api)->limit(PHP_INT_MAX)->offset(PHP_INT_MAX)->exec()));
    $http->done();
};

return $tests;
