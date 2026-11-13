<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Xml;

use PolygonLab\FactureElectronique\InvalidDocument;

/**
 * Chargement sûr d'un document XML et lecture par XPath.
 *
 * Sécurité : toute déclaration DOCTYPE est refusée (donc aucune entité, interne ou externe, ni DTD),
 * l'accès réseau est interdit (LIBXML_NONET) et les entités ne sont jamais substituées.
 *
 * @internal
 */
final class SafeXml
{
    private function __construct(
        private readonly \DOMDocument $document,
        private readonly \DOMXPath $xpath,
    ) {}

    /**
     * @param array<string, string> $namespaces préfixe => espace de noms, pour les requêtes XPath
     *
     * @throws InvalidDocument si le XML est vide, mal formé ou contient une DTD
     */
    public static function load(string $xml, array $namespaces = []): self
    {
        if (trim($xml) === '') {
            throw InvalidDocument::empty();
        }
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw InvalidDocument::doctype();
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $errors !== [] || $document->documentElement === null) {
            $first = $errors[0] ?? null;
            throw InvalidDocument::malformed($first !== null
                ? sprintf('ligne %d, %s', $first->line, rtrim(trim($first->message), '.'))
                : 'document illisible');
        }
        if ($document->doctype !== null) {
            throw InvalidDocument::doctype();
        }

        $xpath = new \DOMXPath($document);
        foreach ($namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return new self($document, $xpath);
    }

    public function root(): \DOMElement
    {
        $root = $this->document->documentElement;
        \assert($root instanceof \DOMElement);

        return $root;
    }

    /**
     * Éléments qui répondent à la requête XPath, dans l'ordre du document.
     *
     * @return list<\DOMElement>
     */
    public function elements(string $query, ?\DOMNode $context = null): array
    {
        $nodes = $this->xpath->query($query, $context);
        $elements = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement) {
                    $elements[] = $node;
                }
            }
        }

        return $elements;
    }

    public function element(string $query, ?\DOMNode $context = null): ?\DOMElement
    {
        return $this->elements($query, $context)[0] ?? null;
    }

    /**
     * Texte du premier élément trouvé, sans espaces de début et de fin ; null s'il est absent ou vide.
     */
    public function text(string $query, ?\DOMNode $context = null): ?string
    {
        $element = $this->element($query, $context);

        return $element === null ? null : self::clean($element->textContent);
    }

    /**
     * Texte de tous les éléments trouvés, vides exclus.
     *
     * @return list<string>
     */
    public function texts(string $query, ?\DOMNode $context = null): array
    {
        $texts = [];
        foreach ($this->elements($query, $context) as $element) {
            $text = self::clean($element->textContent);
            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * Attribut du premier élément trouvé ; null s'il est absent ou vide.
     */
    public function attribute(string $query, string $attribute, ?\DOMNode $context = null): ?string
    {
        $element = $this->element($query, $context);

        return $element === null || !$element->hasAttribute($attribute)
            ? null
            : self::clean($element->getAttribute($attribute));
    }

    private static function clean(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
