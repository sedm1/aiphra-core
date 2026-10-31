# Транспорт и пагинация

Встроенные провайдеры по умолчанию выполняют операции через CalDAV.
Enum `AiphraCore\Method` содержит `CalDav` и `Api`. Для Google REST API явно укажите `setMethod(Method::Api)`:

```php
use AiphraCore\Method;
use AiphraCore\Operation;
use AiphraCore\Providers\GoogleCalendar;

$provider = new GoogleCalendar\Provider($client);

// По умолчанию — CalDAV.
$calendars = $provider->calendars(Operation::Get)
    ->limit(20)
    ->offset(40)
    ->exec();

// Для этого запроса — Google REST API.
$apiCalendars = $provider->calendars(Operation::Get)
    ->setMethod(Method::Api)
    ->limit(20)
    ->offset(40)
    ->exec();
```

Изменение транспорта в запросе не меняет исходный `$provider`. Если вызвать
`$provider->setMethod(Method::Api)` до выбора операции, этот выбор наследуют его новые запросы.
`setMethod(Method::CalDav)` возвращает базовый транспорт. Неподдерживаемая реализация приводит
к `UnsupportedOperationException` до HTTP-запроса; автоматического переключения нет.

Конструктор Google больше не принимает `methods`, константа `METHODS` удалена.
Выбранное значение передаётся в `Provider::operations(Method $method)`. В Google-провайдере
ветка `Method::Api` явно связывает `getCalendar` и `getCalendars` с API-обработчиками,
а `Method::CalDav` использует операции базового CalDAV-провайдера.
Имена обработчиков не определяют маршрутизацию: используются непосредственные ссылки на методы PHP.
Идентификатор CalDAV — URL календаря; идентификатор Google API — ID из ответа API.
Для получения календаря по API ID также выбирайте `setMethod(Method::Api)`.

## Семантика limit и offset

Фильтры применяются первыми. Затем пропускаются `offset` подходящих календарей
и возвращаются не более `limit` следующих. Сохраняется порядок ответа сервера.
Для CalDAV повторы календаря в нескольких папках удаляются до отсчёта диапазона.

- По умолчанию `offset = 0`, а лимит не задан — читается весь список.
- `limit(0)` возвращает пустую коллекцию без запросов, включая discovery и OAuth.
- Отрицательные значения отклоняются через `ValidationException`.
- Диапазон задаётся только после `calendars(Operation::Get)`.
- При выборе новой операции фильтры и диапазон сбрасываются; исходный запрос не меняется.

## Какие запросы экономятся

**Google API.** Для небольшого диапазона без фильтров `maxResults` равен
`offset + limit`, но не превышает 250. Для больших диапазонов и локальных фильтров
используются страницы по 250. Следующая страница запрашивается только если результат
ещё не заполнен. API поддерживает `pageToken`, поэтому большой `offset` требует
прочитать предыдущие страницы. [Параметры Google CalendarList](https://developers.google.com/workspace/calendar/api/v3/reference/calendarList/list).

**CalDAV.** `PROPFIND` читает свойства текущей папки целиком; стандартных параметров
`limit` и `offset` у него нет. Когда набран нужный результат, остальные папки
не запрашиваются. [Описание PROPFIND](https://www.rfc-editor.org/rfc/rfc4918.html#section-9.1).

Новых запросов для подсчёта общего количества календарей библиотека не делает.
Результат остаётся обычной коллекцией `Calendars`.
