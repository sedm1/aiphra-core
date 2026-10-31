<?php

namespace AiphraCore\CalDav\Internal\Xml;

use DOMElement;

/**
 * Один ресурс из ответа сервера: адрес и успешно прочитанные XML-свойства.
 * @internal
 */
final readonly class Resource {
    /**
     * @param array<string, array<string, DOMElement>> $properties Свойства сгруппированы по XML namespace и имени.
     */
    public function __construct(public string $url, private array $properties) {}

    public function text(string $namespace, string $property): string {
        return $this->properties[$namespace][$property]->textContent ?? '';
    }

    /**
     * Ссылки хранятся в элементах DAV:href; адрес может быть относительным.
     * @return list<string>
     */
    public function links(string $namespace, string $property): array {
        $element = $this->properties[$namespace][$property] ?? null;
        if ($element === null) {
            return [];
        }
        $links = [];
        foreach ($element->getElementsByTagNameNS('DAV:', 'href') as $link) {
            $links[] = trim($link->textContent);
        }
        return $links;
    }

    public function hasChildElement(string $namespace, string $property, string $childNamespace, string $childName): bool {
        $element = $this->properties[$namespace][$property] ?? null;
        return $element !== null && $element->getElementsByTagNameNS($childNamespace, $childName)->length > 0;
    }
}
