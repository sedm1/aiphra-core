<?php

namespace AiphraCore\Providers\Concerns;

use AiphraCore\Exceptions\ValidationException;
use AiphraCore\Objects\Calendars\{Calendar, Calendars};
use AiphraCore\Operation;

/**
 * Фильтры применяются до offset и limit; чтение прекращается сразу после заполнения результата.
 * @internal
 */
trait CalendarList {
    protected ?int $limit = null;
    protected int $offset = 0;

    final public function limit(int $limit): static {
        $this->requireCalendarList();
        if ($limit < 0) {
            throw new ValidationException('Limit must not be negative.');
        }
        $this->limit = $limit;
        return $this;
    }

    final public function offset(int $offset): static {
        $this->requireCalendarList();
        if ($offset < 0) {
            throw new ValidationException('Offset must not be negative.');
        }
        $this->offset = $offset;
        return $this;
    }

    /**
     * @param iterable<Calendar> $source
     */
    final protected function collectCalendars(iterable $source): Calendars {
        $result = new Calendars();
        if ($this->limit === 0) {
            return $result;
        }
        $skipped = 0;
        foreach ($source as $calendar) {
            if (!$this->matches($calendar)) {
                continue;
            }
            if ($skipped < $this->offset) {
                ++$skipped;
                continue;
            }
            $result->add($calendar);
            if ($this->limit !== null && count($result) >= $this->limit) {
                break;
            }
        }
        return $result;
    }

    /**
     * Локальные фильтры могут потребовать много строк, поэтому для них берём полную страницу.
     * @param positive-int $maximum
     * @return positive-int
     */
    final protected function requestPageSize(int $maximum): int {
        if ($this->limit === null || $this->filters || $this->offset >= $maximum) {
            return $maximum;
        }
        return max(1, $this->offset + min($this->limit, $maximum - $this->offset));
    }

    private function requireCalendarList(): void {
        if ($this->operation !== Operation::Get || $this->single) {
            throw new ValidationException('Pagination requires calendars(Operation::Get).');
        }
    }
}
