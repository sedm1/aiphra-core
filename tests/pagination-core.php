<?php

declare(strict_types=1);

use AiphraCore\Exceptions\ValidationException;
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Objects\Calendars\{Calendar, Calendars};
use AiphraCore\Method;
use AiphraCore\Operation;
use AiphraCore\Providers\AbstractProvider;

$tests = [];

$tests['limit and offset select matching calendars in order'] = static function (): void {
    $provider = new class extends AbstractProvider {
        protected function operations(Method $method): array {
            return ['getCalendars' => $this->getCalendars(...)];
        }
        protected function getCalendars(): Calendars {
            return $this->collectCalendars([
                new Calendar('other', 'Other'), new Calendar('one', 'Wanted A'),
                new Calendar('two', 'Wanted B'), new Calendar('three', 'Wanted C'),
            ]);
        }
    };
    $result = $provider->calendars(Operation::Get)
        ->addFilter(Field::genFilter('name', Operator::Contains, ['Wanted']))->offset(1)->limit(1)->exec();
    same(['two'], array_map(fn (Calendar $calendar): string => $calendar->getId(), $result->all()));
};

$tests['zero limits and offsets beyond the list return empty collections'] = static function (): void {
    $provider = new MemoryProvider();
    same(0, count($provider->calendars(Operation::Get)->limit(0)->exec()));
    same(0, count($provider->calendars(Operation::Get)->offset(20)->limit(2)->exec()));
    same(0, count($provider->calendars(Operation::Get)->offset(PHP_INT_MAX)->limit(PHP_INT_MAX)->exec()));
    same('personal', $provider->calendars(Operation::Get)->offset(1)->exec()->all()[0]->getId());
};

$tests['pagination is repeatable and does not leak into other queries'] = static function (): void {
    $provider = new MemoryProvider();
    $query = $provider->calendars(Operation::Get)->offset(1)->limit(1);
    same('personal', $query->exec()->all()[0]->getId());
    same('personal', $query->exec()->all()[0]->getId());
    same(2, count($provider->calendars(Operation::Get)->exec()));
    same(2, count($query->calendars(Operation::Get)->exec()));
};

$tests['pagination rejects invalid values and non-list operations'] = static function (): void {
    $provider = new MemoryProvider();
    raises(ValidationException::class, fn () => $provider->limit(1));
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->offset(1));
    raises(ValidationException::class, fn () => $provider->calendars(Operation::Add)->limit(1));
    raises(ValidationException::class, fn () => $provider->calendars(Operation::Get)->limit(-1));
    raises(ValidationException::class, fn () => $provider->calendars(Operation::Get)->offset(-1));
};

$tests['setMethod is explicit and query overrides do not change the base provider'] = static function (): void {
    $provider = new class extends AbstractProvider {
        protected function operations(Method $method): array {
            return match ($method) {
                Method::CalDav => ['getCalendars' => $this->getCalendars(...)],
                Method::Api => ['getCalendars' => $this->getCalendarsViaApi(...)],
            };
        }
        protected function getCalendars(): Calendars { return new Calendars([new Calendar('caldav', 'Default')]); }
        protected function getCalendarsViaApi(): Calendars { return new Calendars([new Calendar('api', 'API')]); }
    };
    same('api', $provider->calendars(Operation::Get)->setMethod(Method::Api)->exec()->all()[0]->getId());
    same('caldav', $provider->calendars(Operation::Get)->exec()->all()[0]->getId());
    $provider->setMethod(Method::Api);
    same('api', $provider->calendars(Operation::Get)->exec()->all()[0]->getId());
    same('caldav', $provider->calendars(Operation::Get)->setMethod(Method::CalDav)->exec()->all()[0]->getId());
};

return $tests;
