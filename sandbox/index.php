<?php

use AiphraCore\Providers\GoogleCalendar;
use AiphraCore\Operation;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) ?: [];

$googleClient = new GoogleCalendar\Client(new GoogleCalendar\OAuthCredentials(clientId: $env['GOOGLE_CLIENT_ID'] ?? '',
    clientSecret:                                                                       $env['GOOGLE_CLIENT_SECRET'] ?? '',
    refreshToken:                                                                       $env['GOOGLE_REFRESH_TOKEN'] ?? '',));

$googleProvider = new GoogleCalendar\Provider($googleClient, $env['GOOGLE_CALDAV_URL'] ?? 'https://apidata.googleusercontent.com/caldav/v2/');

$calendars = $googleProvider->calendars(Operation::Get)->exec();
header('Content-Type: text/html; charset=utf-8');
foreach ($calendars as $calendar) {
    echo htmlspecialchars($calendar->getId(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': '
        . htmlspecialchars($calendar->getName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>';
}
