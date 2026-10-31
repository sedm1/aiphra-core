<?php

declare(strict_types=1);

use AiphraCore\Exceptions\{InvalidConfigurationException, ProviderResponseException, UnsupportedOperationException, ValidationException};
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Http\{CurlClient, Response};
use AiphraCore\Objects\Calendars\{Calendar, Calendars};
use AiphraCore\Method;
use AiphraCore\Operation;
use AiphraCore\Providers\AbstractProvider;
use AiphraCore\Support\Arrays\AbstractObjectArray;

$tests = [];

final class MemoryProvider extends AbstractProvider {
    protected function operations(Method $method): array {
        return $method === Method::CalDav ? ['getCalendars' => $this->getCalendars(...)] : [];
    }

    protected function getCalendars(): Calendars {
        return $this->collectCalendars([
            new Calendar('work', 'Work'), new Calendar('personal', 'Personal'),
        ]);
    }
}

/**
 * @extends AbstractObjectArray<Calendar>
 */
final class UnconfiguredCollection extends AbstractObjectArray {}

$tests['custom providers work without a CalDAV client or traits'] = static function (): void {
    $provider = new MemoryProvider();
    same(2, count($provider->calendars(Operation::Get)->exec()));
    same('Work', $provider->calendars(Operation::Get)->addFilter(eq('id', 'work'))->exec()->all()[0]->getName());
    raises(UnsupportedOperationException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', 'work'))->exec());
    raises(UnsupportedOperationException::class, fn () => $provider->calendars(Operation::Add)->exec());
};

$tests['core validates queries and keeps fluent filters independent'] = static function (): void {
    $provider = new MemoryProvider();
    raises(ValidationException::class, fn () => $provider->exec());
    raises(ValidationException::class, fn () => $provider->addFilter(eq('name', 'Work')));
    raises(ValidationException::class, fn () => $provider->calendars(Operation::Get)->addFilter(eq('title', 'Work')));
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', '')));
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', 'one'))->addFilter(eq('id', 'two')));
    raises(ValidationException::class, fn () => Field::genFilter('name', Operator::Equals, [123]));
    $work = $provider->calendars(Operation::Get)->addFilter(eq('name', 'Work'));
    $personal = $provider->calendars(Operation::Get)->addFilter(eq('name', 'Personal'));
    same('work', $work->exec()->all()[0]->getId());
    same('personal', $personal->exec()->all()[0]->getId());
    same(2, count($provider->calendars(Operation::Get)->exec()));
};

$tests['setMethod selects alternative implementations without transport dependencies'] = static function (): void {
    $provider = new class extends AbstractProvider {
        protected function operations(Method $method): array {
            return $method === Method::Api ? ['getCalendars' => $this->readLocalCalendars(...)] : [];
        }
        private function readLocalCalendars(): Calendars { return new Calendars([new Calendar('local', 'Local')]); }
    };
    raises(UnsupportedOperationException::class, fn () => $provider->calendars(Operation::Get)->exec());
    same('local', $provider->setMethod(Method::Api)->calendars(Operation::Get)->exec()->all()[0]->getId());
};

$tests['unregistered methods are never exposed as supported operations'] = static function (): void {
    $private = new class extends AbstractProvider {
        public function listDirectly(): Calendars { return $this->getCalendars(); }
        private function getCalendars(): Calendars { return new Calendars(); }
    };
    same(0, count($private->listDirectly()));
    $public = new class extends AbstractProvider { public function getCalendars(): Calendars { return new Calendars(); } };
    $static = new class extends AbstractProvider { protected static function getCalendars(): Calendars { return new Calendars(); } };
    foreach ([$private, $public, $static, new class extends AbstractProvider {}] as $provider) {
        raises(UnsupportedOperationException::class, fn () => $provider->calendars(Operation::Get)->exec());
    }
};

$tests['the bundled HTTP client reports a missing optional curl extension'] = static function (): void {
    if (!extension_loaded('curl')) {
        raises(InvalidConfigurationException::class, fn () => new CurlClient());
    } else {
        raises(InvalidConfigurationException::class, fn () => new CurlClient(timeout: 0));
    }
};

$tests['operation handlers must return the selected object or collection'] = static function (): void {
    $wrongList = new class extends AbstractProvider {
        protected function operations(Method $method): array {
            return ['getCalendars' => $this->getCalendars(...)];
        }
        protected function getCalendars(): Calendar { return new Calendar('one', 'One'); }
    };
    $wrongCalendar = new class extends AbstractProvider {
        protected function operations(Method $method): array {
            return ['getCalendar' => $this->getCalendar(...)];
        }
        protected function getCalendar(): Calendars { return new Calendars(); }
    };
    raises(InvalidConfigurationException::class, fn () => $wrongList->calendars(Operation::Get)->exec());
    raises(InvalidConfigurationException::class, fn () => $wrongCalendar->calendar(Operation::Get)->addFilter(eq('id', 'one'))->exec());
};

$tests['collections require a declared item type even when empty'] = static function (): void {
    raises(LogicException::class, fn () => new UnconfiguredCollection());
    raises(LogicException::class, fn () => UnconfiguredCollection::getItemType());
    same(Calendar::class, Calendars::getItemType());
    $collection = new Calendars();
    same(0, count($collection));
    $calendar = new Calendar('one', 'One');
    $collection->add($calendar);
    same([$calendar], iterator_to_array($collection));
};

$tests['JSON responses accept objects including numeric keys and reject other roots'] = static function (): void {
    same([1 => 'one', 'nested' => ['id' => 'two']], (new Response(200, '{"1":"one","nested":{"id":"two"}}'))->json());
    same([], (new Response(200, '{}'))->json());
    foreach (['[]', '[1,2]', 'null', 'true', '"text"', '{broken'] as $json) {
        raises(ProviderResponseException::class, fn () => (new Response(200, $json))->json());
    }
};

$tests['empty HTTP methods are rejected before sending requests'] = static function (): void {
    if (extension_loaded('curl')) {
        raises(InvalidConfigurationException::class, fn () => (new CurlClient())->request('', 'https://example.invalid/'));
    }
};

return $tests;
