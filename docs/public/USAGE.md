# Использование Aiphra

Пакеты устанавливаются независимо: `aiphra/core` — ядро без источников,
`aiphra/caldav` — общий CalDAV, `aiphra/google` — Google Calendar.
Composer автоматически устанавливает зависимости выбранного пакета.
PHP namespace библиотеки — `AiphraCore\`, отдельно от namespace приложения `Aiphra\`.
Для Google общий CalDAV также является обязательной зависимостью.
[Выбор транспорта и пагинация списка](QUERIES.md).

## Установка из каталога пакетов

После первого релиза один раз подключите каталог пакетов:

```bash
composer config repositories.aiphra composer https://sedm1.github.io/aiphra-core/
```

Каталог публикуется автоматически при выпуске стабильного релиза ядра.
После этого установите нужный пакет:

```bash
# Только ядро
composer require aiphra/core

# Общий CalDAV без Google
composer require aiphra/caldav

# Google вместе с ядром и CalDAV
composer require aiphra/google
```

Нужен PHP 8.2+. Для CalDAV требуются `ext-dom`, `ext-libxml` и `ext-filter`; Google также использует `ext-filter`.
Встроенный HTTP-клиент использует
`ext-curl`. Вместо него можно передать свою реализацию `Http\HttpClient`.
Ядру эти расширения не нужны.

## Подключение в другое локальное приложение

До публикации добавьте в `composer.json` приложения локальный репозиторий.
Путь должен вести в `core/packages/*` вашего checkout:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/absolute/path/to/core/packages/*",
            "options": {
                "symlink": true,
                "versions": {
                    "aiphra/core": "0.0.x-dev",
                    "aiphra/caldav": "0.0.x-dev",
                    "aiphra/google": "0.0.x-dev"
                }
            }
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "aiphra/google": "~0.0.1"
    }
}
```

Затем выполните `composer update`. Для одного ядра замените зависимость на
`aiphra/core`; остальные пакеты не установятся. `minimum-stability: dev`
в примере разрешает локальные версии всех транзитивных пакетов. В монорепозитории
вместо этого используются явные `@dev` для трёх зависимостей разработки.

## Собственный провайдер

Для провайдера из памяти, другого API или нового источника достаточно ядра:

```php
use AiphraCore\Objects\Calendars\{Calendar, Calendars};
use AiphraCore\Providers\AbstractProvider;
use AiphraCore\Method;
use AiphraCore\Operation;

final class LocalProvider extends AbstractProvider {
    protected function operations(Method $method): array {
        return $method === Method::Api ? ['getCalendars' => $this->readLocalCalendars(...)] : [];
    }

    private function readLocalCalendars(): Calendars {
        return $this->collectCalendars([new Calendar('local', 'Мой календарь')]);
    }
}

$calendars = (new LocalProvider())->calendars(Operation::Get)->setMethod(Method::Api)->exec();
```

`operations(Method $method)` явно перечисляет поддерживаемые операции для выбранного
транспорта и возвращает ссылки на их обработчики. Обработчик `getCalendars` возвращает
`Calendars`, обработчик `getCalendar` — `Calendar`. Имена самих PHP-методов могут быть любыми.
Провайдер хранит нужные клиенты; у базового класса нет обязательного конструктора.
Базовые реализации встроенных провайдеров используют CalDAV.
Для списка вызывайте `collectCalendars($source)`: он применит фильтры, offset и limit
к переданному массиву или ленивому итератору.
Одного наличия метода в классе недостаточно: незарегистрированные операции завершаются
`UnsupportedOperationException` до обращения к сети.
При реализации обработчика `getCalendar` провайдер должен потребовать фильтр `id Equals`,
а при несовпадении остальных фильтров — вернуть `ResourceNotFoundException`.
