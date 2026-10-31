<?php

declare(strict_types=1);

use AiphraCore\CalDav\Client as CalDavClient;
use AiphraCore\Exceptions\{AuthenticationException, AuthorizationException, ProviderResponseException, ResourceNotFoundException, UnsupportedOperationException, ValidationException};
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Http\{HttpClient, Response};
use AiphraCore\Method;
use AiphraCore\Operation;
use AiphraCore\Providers\AbstractProvider;
use AiphraCore\Providers\CalDav\Provider;
use AiphraCore\Providers\GoogleCalendar;
use AiphraCore\Providers\Traits\GetCalendars;

$tests = [];

$tests['Google supplies OAuth and URL while using the common CalDAV flow'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', static function ($headers, $body): Response {
        parse_str($body, $form);
        same('secret&=', $form['client_secret']);
        return new Response(200, '{"access_token":"test-token","expires_in":3600}');
    })->expect('PROPFIND', 'https://apidata.googleusercontent.com/caldav/v2/', static function ($headers): Response {
        same('Bearer test-token', $headers['Authorization']);
        return multi(entry('/caldav/v2/', '<c:calendar-home-set><d:href>/caldav/v2/account/</d:href></c:calendar-home-set>'));
    }, '0')->expect('PROPFIND', 'https://apidata.googleusercontent.com/caldav/v2/account/', multi(entry('/caldav/v2/account/events/',
        '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Google</d:displayname>')), '1');
    $provider = new GoogleCalendar\Provider(new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret&=', 'refresh'), $http));
    same('Google', $provider->calendars(Operation::Get)->exec()->all()[0]->getName());
    $http->done();
};

$tests['explicit Google API methods keep filtering across pages'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200,
            '{"items":[{"id":"personal","summary":"Personal"}],"nextPageToken":"page 2"}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250&pageToken=page%202', new Response(200,
            '{"items":[{"id":"work@example.com","summary":"Work"}]}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/calendars/work%40example.com', new Response(200,
            '{"id":"work@example.com","summary":"Work"}'));
    $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
    $provider = new GoogleCalendar\Provider($client);
    $provider->setMethod(Method::Api);
    $list = $provider->calendars(Operation::Get)->addFilter(eq('name', 'Work'))->exec();
    same(1, count($list));
    $calendar = $provider->calendar(Operation::Get)->addFilter(eq('id', $list->all()[0]->getId()))->exec();
    same('work@example.com', $calendar->getId());
    same('Work', $calendar->getName());
    $http->done();
};

$tests['explicitly selecting API for getCalendar keeps getCalendars on CalDAV'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('PROPFIND', 'https://apidata.googleusercontent.com/caldav/v2/', multi(entry('/caldav/v2/',
            '<c:calendar-home-set><d:href>/caldav/v2/account/</d:href></c:calendar-home-set>')), '0')
        ->expect('PROPFIND', 'https://apidata.googleusercontent.com/caldav/v2/account/', multi(entry('/caldav/v2/account/events/',
            '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>CalDAV</d:displayname>')), '1')
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/calendars/work%40example.com', new Response(200,
            '{"id":"work@example.com","summary":"API"}'));
    $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
    $provider = new GoogleCalendar\Provider($client);
    same('CalDAV', $provider->calendars(Operation::Get)->exec()->all()[0]->getName());
    same('API', $provider->calendar(Operation::Get)->setMethod(Method::Api)->addFilter(eq('id', 'work@example.com'))->exec()->getName());
    $http->done();
};

$tests['API rejects missing ID before HTTP and repeated pagination tokens'] = static function (): void {
    $http = new FakeHttp();
    $provider = new GoogleCalendar\Provider(new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http));
    $provider->setMethod(Method::Api);
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->exec());
    same([], $http->requests);
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200, '{"items":[],"nextPageToken":"repeat"}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250&pageToken=repeat', new Response(200, '{"items":[],"nextPageToken":"repeat"}'));
    raises(ProviderResponseException::class, fn () => $provider->calendars(Operation::Get)->exec());
    $http->done();
};

$tests['Google rejects malformed lists and null calendar names'] = static function (): void {
    foreach (['{"items":null}', '{"items":false}', '{"items":[{"id":"one","summary":null}]}'] as $json) {
        $http = new FakeHttp();
        $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
            ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200, $json));
        $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
        $provider = new GoogleCalendar\Provider($client);
        $provider->setMethod(Method::Api);
        raises(ProviderResponseException::class, fn () => $provider->calendars(Operation::Get)->exec());
        $http->done();
    }
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":3600}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/calendars/one', new Response(200, '{"id":"one","summary":null}'));
    $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
    $provider = new GoogleCalendar\Provider($client);
    $provider->setMethod(Method::Api);
    raises(ProviderResponseException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', 'one'))->exec());
    $http->done();
};

$tests['optional API fields can be omitted and OAuth tokens are reused'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"token","expires_in":"3600"}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200, '{}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250', new Response(200, '{"items":[{"id":"one"}]}'))
        ->expect('GET', 'https://www.googleapis.com/calendar/v3/calendars/one', new Response(200, '{"id":"one"}'));
    $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
    $provider = new GoogleCalendar\Provider($client);
    $provider->setMethod(Method::Api);
    same(0, count($provider->calendars(Operation::Get)->exec()));
    same('', $provider->calendars(Operation::Get)->exec()->all()[0]->getName());
    same('', $provider->calendar(Operation::Get)->addFilter(eq('id', 'one'))->exec()->getName());
    $http->done();
};

$tests['invalid token lifetimes produce authentication errors without overflow'] = static function (): void {
    foreach ([0, -1, 0.5, true, null, 'invalid', '999999999999999999999999', PHP_INT_MAX] as $expiresIn) {
        $http = new FakeHttp();
        $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200,
            json_encode(['access_token' => 'token', 'expires_in' => $expiresIn], JSON_THROW_ON_ERROR)));
        $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
        raises(AuthenticationException::class, fn () => $client->request('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250'));
        $http->done();
    }
};

$tests['a rejected access token is refreshed on the next request'] = static function (): void {
    $url = 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250';
    $http = new FakeHttp();
    $http->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"old","expires_in":3600}'))
        ->expect('GET', $url, new Response(200, '{}'))
        ->expect('GET', $url, new Response(401))
        ->expect('POST', 'https://oauth2.googleapis.com/token', new Response(200, '{"access_token":"new","expires_in":3600}'))
        ->expect('GET', $url, static function ($headers): Response {
            same('Bearer new', $headers['Authorization']);
            return new Response(200, '{}');
        });
    $client = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials('client', 'secret', 'refresh'), $http);
    $client->request('GET', $url);
    raises(AuthenticationException::class, fn () => $client->request('GET', $url));
    $client->request('GET', $url);
    $http->done();
};

return $tests;
