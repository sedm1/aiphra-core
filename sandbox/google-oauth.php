<?php

declare(strict_types=1);

session_start();

/**
 * Loads a local sandbox configuration without adding a dotenv dependency to the library.
 *
 * @return array<string, string>
 */
function loadConfiguration(): array
{
    $configuration = [];
    $environmentFile = __DIR__ . '/.env';

    if (is_file($environmentFile)) {
        $values = parse_ini_file($environmentFile, false, INI_SCANNER_RAW);

        if ($values === false) {
            throw new RuntimeException('Unable to parse sandbox/.env.');
        }

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $configuration[$key] = $value;
            }
        }
    }

    foreach (array_keys($configuration) as $key) {
        $environmentValue = getenv($key);

        if ($environmentValue !== false) {
            $configuration[$key] = $environmentValue;
        }
    }

    foreach ([
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'GOOGLE_REDIRECT_URI',
        'GOOGLE_SCOPES',
    ] as $key) {
        $environmentValue = getenv($key);

        if ($environmentValue !== false) {
            $configuration[$key] = $environmentValue;
        }
    }

    return $configuration;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, string> $configuration
 */
function configurationValue(array $configuration, string $key): string
{
    return trim($configuration[$key] ?? '');
}

/**
 * @param array<string, string> $values
 * @return array{status: int, body: string}
 */
function postForm(string $url, array $values): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The curl PHP extension is required for the local OAuth helper.');
    }

    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the OAuth HTTP request.');
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($values, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false) {
        throw new RuntimeException(sprintf('OAuth token request failed: %s', $error));
    }

    return ['status' => $status, 'body' => $body];
}

function renderPage(string $title, string $content): never
{
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="ru"><meta charset="utf-8">';
    echo '<title>' . escapeHtml($title) . '</title>';
    echo '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px}code,textarea{font-family:ui-monospace,monospace}textarea{width:100%;min-height:7em} .error{color:#a00}</style>';
    echo '<h1>' . escapeHtml($title) . '</h1>' . $content;
    echo '</html>';
    exit;
}

try {
    $configuration = loadConfiguration();
} catch (RuntimeException $exception) {
    renderPage('Ошибка конфигурации', '<p class="error">' . escapeHtml($exception->getMessage()) . '</p>');
}

$clientId = configurationValue($configuration, 'GOOGLE_CLIENT_ID');
$clientSecret = configurationValue($configuration, 'GOOGLE_CLIENT_SECRET');
$redirectUri = configurationValue($configuration, 'GOOGLE_REDIRECT_URI');
$scopes = configurationValue($configuration, 'GOOGLE_SCOPES');

if (!$clientId || !$clientSecret || !$redirectUri) {
    renderPage(
        'Нужна локальная OAuth-конфигурация',
        '<p>Скопируйте <code>sandbox/.env.example</code> в <code>sandbox/.env</code> и заполните client ID и client secret.</p>'
    );
}

if (!$scopes) {
    $scopes = 'https://www.googleapis.com/auth/calendar';
}

if (isset($_GET['error'])) {
    $error = is_string($_GET['error']) ? $_GET['error'] : 'Invalid OAuth error response.';
    renderPage(
        'Google отклонил авторизацию',
        '<p class="error">' . escapeHtml($error) . '</p>'
    );
}

if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(32));
    $_SESSION['google_oauth_state'] = $state;

    $authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => $scopes,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);

    header('Location: ' . $authorizationUrl, true, 302);
    exit;
}

$state = $_GET['state'] ?? '';
$expectedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if (!is_string($state) || !is_string($expectedState) || !$expectedState || !hash_equals($expectedState, $state)) {
    renderPage('Ошибка OAuth state', '<p class="error">Авторизация не связана с текущей сессией. Начните заново.</p>');
}

try {
    $code = $_GET['code'];
    if (!is_string($code) || !$code) {
        throw new RuntimeException('Invalid OAuth authorization code.');
    }
    $response = postForm('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
    $token = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    renderPage('Не удалось получить токен', '<p class="error">' . escapeHtml($exception->getMessage()) . '</p>');
}

if (!is_array($token) || !isset($token['refresh_token']) || !is_string($token['refresh_token'])) {
    $description = is_array($token) && isset($token['error_description']) && is_string($token['error_description'])
        ? $token['error_description']
        : 'Google did not return a refresh token.';

    renderPage('Не удалось получить refresh token', '<p class="error">' . escapeHtml($description) . '</p>');
}

renderPage(
    'Refresh token получен',
    '<p>Скопируйте значение ниже в <code>GOOGLE_REFRESH_TOKEN</code> файла <code>sandbox/.env</code>. Не добавляйте его в Git или логи.</p>' .
    '<textarea readonly>' . escapeHtml($token['refresh_token']) . '</textarea>'
);
