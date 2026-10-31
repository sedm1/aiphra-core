<?php

namespace AiphraCore\Support\Arrays;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * Типизированный список объектов
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
abstract class AbstractObjectArray implements Countable, IteratorAggregate {

    /**
     * Класс объекта, который будет являться типом элемент массива
     *
     * @var class-string|''
     */
    protected const ITEM_TYPE = '';

    /**
     * @var list<T>
     */
    private array $items = [];

    /**
     * @param list<T> $items
     */
    final public function __construct(array $items = []) {
        self::getItemType();
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    /**
     * @param T $item
     */
    final public function add(object $item): static {
        $itemType = self::getItemType();

        if (!is_a($item, $itemType)) {
            throw new InvalidArgumentException(sprintf(
                '%s accepts only %s instances; %s given.',
                static::class,
                $itemType,
                $item::class
            ));
        }

        $this->items[] = $item;

        return $this;
    }

    /**
     * @return class-string
     */
    final public static function getItemType(): string {
        $itemType = static::ITEM_TYPE;
        if (!class_exists($itemType) && !interface_exists($itemType)) {
            throw new LogicException(static::class . ' must declare a valid ITEM_TYPE.');
        }
        return $itemType;
    }

    /**
     * @return list<T>
     */
    final public function all(): array {
        return $this->items;
    }

    final public function count(): int {
        return count($this->items);
    }

    /**
     * @return Traversable<int, T>
     */
    final public function getIterator(): Traversable {
        return new ArrayIterator($this->items);
    }
}
