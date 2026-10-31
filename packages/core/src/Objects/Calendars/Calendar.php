<?php

namespace AiphraCore\Objects\Calendars;

/**
 * Класс-объект календарь
 */
final readonly class Calendar {

    public const FIELDS = ['id' => 'getId', 'name' => 'getName'];

    public function __construct(
        private string $id,
        private string $name,
    ) {}

    /**
     * Получить id календаря
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Получить название календаря
     */
    public function getName(): string {
        return $this->name;
    }


}
