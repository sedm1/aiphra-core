<?php

namespace AiphraCore\CalDav\Internal\Xml;

use AiphraCore\Exceptions\ProviderResponseException;
use AiphraCore\Http\{Errors, Response};
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HTTP 207 содержит несколько ресурсов, у каждого свои свойства и статусы чтения.
 * @internal
 */
final class ResponseParser {
    /**
     * @return list<Resource>
     */
    public static function parse(Response $response): array {
        Errors::check($response);
        if ($response->status !== 207) {
            throw new ProviderResponseException('Expected CalDAV 207 Multi-Status.');
        }
        $xpath = new DOMXPath(self::document($response->body));
        $xpath->registerNamespace('d', 'DAV:');

        $resources = [];
        foreach ($xpath->query('/d:multistatus/d:response') as $entry) {
            $resources[] = self::readResource($xpath, $entry);
        }
        return $resources;
    }

    private static function readResource(DOMXPath $xpath, DOMNode $entry): Resource {
        $status = $xpath->evaluate('string(d:status)', $entry);
        if ($status) {
            Errors::check(new Response(self::status($status)));
        }
        $url = trim($xpath->evaluate('string(d:href)', $entry));
        if (!$url) {
            throw new ProviderResponseException('CalDAV response omitted href.');
        }
        $properties = [];
        foreach (self::successfulPropertyGroups($xpath, $entry) as $group) {
            foreach ($xpath->query('d:prop/*', $group) as $property) {
                if ($property instanceof DOMElement) {
                    $properties[$property->namespaceURI ?? ''][$property->localName] = $property;
                }
            }
        }
        return new Resource($url, $properties);
    }

    /**
     * propstat объединяет свойства с одинаковым статусом: 404 означает отсутствие свойства, а не ресурса.
     * @return iterable<DOMNode>
     */
    private static function successfulPropertyGroups(DOMXPath $xpath, DOMNode $entry): iterable {
        foreach ($xpath->query('d:propstat', $entry) as $group) {
            $code = self::status($xpath->evaluate('string(d:status)', $group));
            if ($code === 404) {
                continue;
            }
            Errors::check(new Response($code));
            yield $group;
        }
    }

    private static function document(string $xml): DOMDocument {
        if (!$xml) {
            throw new ProviderResponseException('CalDAV returned empty XML.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$valid || $document->doctype !== null || $root?->localName !== 'multistatus' || $root->namespaceURI !== 'DAV:') {
            throw new ProviderResponseException('Invalid CalDAV multistatus XML.');
        }
        return $document;
    }

    private static function status(string $line): int {
        if (!preg_match('/^HTTP\/\S+ (\d{3})(?:\s|$)/', $line, $matches)) {
            throw new ProviderResponseException('Invalid CalDAV resource status.');
        }
        return filter_var($matches[1], FILTER_VALIDATE_INT)
            ?: throw new ProviderResponseException('Invalid CalDAV resource status.');
    }
}
