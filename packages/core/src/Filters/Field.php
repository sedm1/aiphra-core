<?php

namespace AiphraCore\Filters;

use AiphraCore\Exceptions\ValidationException;

/**
 * Неизменяемое условие. Сравнения точные и чувствительны к регистру.
 */
final readonly class Field {
    /**
     * @param non-empty-list<string> $values
     */
    private function __construct(public string $name, public Operator $operator, public array $values) {}

    /**
     * @param array<mixed> $values
     */
    public static function genFilter(string $name, Operator $operator, array $values): self {
        if (!$name || !array_is_list($values) || !$values) {
            throw new ValidationException('A filter needs a field and a non-empty list of values.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new ValidationException('Filter values must be strings.');
            }
        }
        if ($operator !== Operator::In && count($values) !== 1) {
            throw new ValidationException('Equals and Contains accept exactly one value.');
        }
        return new self($name, $operator, $values);
    }

    public function matches(?string $value): bool {
        if ($value === null) {
            return false;
        }
        return match ($this->operator) {
            Operator::Equals => $value === $this->values[0],
            Operator::Contains => str_contains($value, $this->values[0]),
            Operator::In => in_array($value, $this->values, true),
        };
    }
}
