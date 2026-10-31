<?php

namespace AiphraCore\Providers;

use AiphraCore\Exceptions\{InvalidConfigurationException, ValidationException};
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Objects\Calendars\{Calendar, Calendars};
use AiphraCore\Operation;
use AiphraCore\Method;
use AiphraCore\Providers\Concerns\{CalendarList, SelectTransport};
use Closure;

/**
 * Выбор операции и проверка фильтров. Сеть вызывается только из exec().
 */
abstract class AbstractProvider {
    use CalendarList, SelectTransport;

    private ?Operation $operation = null;
    private bool $single = false;
    /**
     * @var list<Field>
     */
    protected array $filters = [];

    final public function calendars(Operation $operation): static {
        return $this->forOperation($operation, false);
    }

    final public function calendar(Operation $operation): static {
        return $this->forOperation($operation, true);
    }

    final public function addFilter(Field $filter): static {
        if ($this->operation === null) {
            throw new ValidationException('Select calendars() or calendar() before adding filters.');
        }
        if (!array_key_exists($filter->name, Calendar::FIELDS)) {
            throw new ValidationException('Unknown Calendar field: ' . $filter->name);
        }
        if ($this->single && $filter->name === 'id') {
            if ($filter->operator !== Operator::Equals || !$filter->values[0]) {
                throw new ValidationException('A single calendar requires a non-empty id Equals filter.');
            }
            foreach ($this->filters as $existing) {
                if ($existing->name === 'id') {
                    throw new ValidationException('A single calendar accepts only one id filter.');
                }
            }
        }
        $this->filters[] = $filter;
        return $this;
    }

    final public function exec(): Calendars|Calendar {
        if ($this->operation === null) {
            throw new ValidationException('Select calendars() or calendar() before exec().');
        }
        $method = $this->operation->value . ($this->single ? 'Calendar' : 'Calendars');
        $handler = $this->operationHandler($method);
        $result = $handler();
        $valid = $this->single ? $result instanceof Calendar : $result instanceof Calendars;
        if (!$valid) {
            $expectedType = $this->single ? Calendar::class : Calendars::class;
            throw new InvalidConfigurationException($method . ' must return ' . $expectedType . '.');
        }
        return $result;
    }

    /**
     * @return array<string, Closure(): (Calendar|Calendars)>
     */
    protected function operations(Method $method): array {
        return [];
    }

    final protected function matches(Calendar $calendar): bool {
        foreach ($this->filters as $filter) {
            $getter = Calendar::FIELDS[$filter->name];
            if (!$filter->matches($calendar->$getter())) {
                return false;
            }
        }
        return true;
    }

    private function forOperation(Operation $operation, bool $single): static {
        $query = clone $this;
        $query->operation = $operation;
        $query->single = $single;
        $query->filters = [];
        $query->limit = null;
        $query->offset = 0;
        return $query;
    }
}
