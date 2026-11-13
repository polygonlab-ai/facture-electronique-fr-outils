<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Internal\Pdf;

/**
 * Analyseur des objets PDF (ISO 32000-1, § 7.2 et § 7.3) : lit une valeur à partir d'une position.
 *
 * @internal
 */
final class Parser
{
    /** Profondeur maximale d'imbrication des tableaux et dictionnaires. */
    private const MAX_DEPTH = 64;

    private const WHITESPACE = "\x00\t\n\x0C\r ";

    private const DELIMITERS = '()<>[]{}/%';

    private int $position;

    private readonly int $length;

    public function __construct(
        private readonly string $data,
        int $offset = 0,
    ) {
        $this->position = $offset;
        $this->length = \strlen($data);
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * Saute les blancs et les commentaires.
     */
    public function skipWhitespace(): void
    {
        while ($this->position < $this->length) {
            $char = $this->data[$this->position];
            if ($char === '%') {
                $end = strcspn($this->data, "\r\n", $this->position);
                $this->position += $end;
            } elseif (str_contains(self::WHITESPACE, $char)) {
                ++$this->position;
            } else {
                return;
            }
        }
    }

    /**
     * Vrai si le mot-clé donné commence à la position courante (après les blancs), suivi d'un séparateur.
     */
    public function nextIsKeyword(string $keyword): bool
    {
        $this->skipWhitespace();
        $end = $this->position + \strlen($keyword);

        return substr($this->data, $this->position, \strlen($keyword)) === $keyword
            && ($end >= $this->length || !self::isRegular($this->data[$end]));
    }

    public function skip(int $bytes): void
    {
        $this->position = min($this->length, $this->position + $bytes);
    }

    /**
     * @throws SyntaxError si aucune valeur valide ne commence à la position courante
     */
    public function parseValue(int $depth = 0): PdfValue
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SyntaxError('Imbrication trop profonde.');
        }
        $this->skipWhitespace();
        if ($this->position >= $this->length) {
            throw new SyntaxError('Fin des données inattendue.');
        }

        $char = $this->data[$this->position];

        return match (true) {
            $char === '<' && ($this->data[$this->position + 1] ?? '') === '<' => $this->parseDictionary($depth),
            $char === '<' => $this->parseHexString(),
            $char === '[' => $this->parseArray($depth),
            $char === '(' => $this->parseLiteralString(),
            $char === '/' => $this->parseName(),
            ($char >= '0' && $char <= '9') || $char === '+' || $char === '-' || $char === '.' => $this->parseNumberOrReference(),
            default => $this->parseKeyword(),
        };
    }

    private function parseDictionary(int $depth): PdfDictionary
    {
        $this->position += 2;
        $entries = [];
        while (true) {
            $this->skipWhitespace();
            if ($this->position >= $this->length) {
                throw new SyntaxError('Dictionnaire non terminé.');
            }
            if (substr($this->data, $this->position, 2) === '>>') {
                $this->position += 2;

                return new PdfDictionary($entries);
            }
            $key = $this->parseValue($depth + 1);
            if (!$key instanceof PdfName) {
                throw new SyntaxError('Clé de dictionnaire invalide.');
            }
            $entries[$key->name] = $this->parseValue($depth + 1);
        }
    }

    private function parseArray(int $depth): PdfArray
    {
        ++$this->position;
        $items = [];
        while (true) {
            $this->skipWhitespace();
            if ($this->position >= $this->length) {
                throw new SyntaxError('Tableau non terminé.');
            }
            if ($this->data[$this->position] === ']') {
                ++$this->position;

                return new PdfArray($items);
            }
            $items[] = $this->parseValue($depth + 1);
        }
    }

    private function parseHexString(): PdfString
    {
        $end = strpos($this->data, '>', $this->position);
        if ($end === false) {
            throw new SyntaxError('Chaîne hexadécimale non terminée.');
        }
        $hex = (string) preg_replace('/[\x00\t\n\x0C\r ]+/', '', substr($this->data, $this->position + 1, $end - $this->position - 1));
        if (preg_match('/\A[0-9A-Fa-f]*\z/', $hex) !== 1) {
            throw new SyntaxError('Chaîne hexadécimale invalide.');
        }
        if (\strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $this->position = $end + 1;

        return new PdfString((string) hex2bin($hex));
    }

    private function parseLiteralString(): PdfString
    {
        ++$this->position;
        $bytes = '';
        $depth = 1;
        while ($this->position < $this->length) {
            $char = $this->data[$this->position++];
            if ($char === '\\') {
                $bytes .= $this->parseEscape();
                continue;
            }
            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return new PdfString($bytes);
            }
            $bytes .= $char;
        }

        throw new SyntaxError('Chaîne littérale non terminée.');
    }

    private function parseEscape(): string
    {
        $char = $this->data[$this->position] ?? '';
        ++$this->position;

        switch ($char) {
            case 'n':
                return "\n";
            case 'r':
                return "\r";
            case 't':
                return "\t";
            case 'b':
                return "\x08";
            case 'f':
                return "\x0C";
            case "\r":
                // Barre oblique en fin de ligne : la ligne continue, la fin de ligne est ignorée.
                if (($this->data[$this->position] ?? '') === "\n") {
                    ++$this->position;
                }

                return '';
            case "\n":
                return '';
        }
        if ($char !== '' && $char >= '0' && $char <= '7') {
            $octal = $char;
            while (\strlen($octal) < 3 && ($next = $this->data[$this->position] ?? '') !== '' && $next >= '0' && $next <= '7') {
                $octal .= $next;
                ++$this->position;
            }

            return \chr(octdec($octal) & 0xFF);
        }

        // \( \) \\ et toute autre lettre : le caractère lui-même.
        return $char;
    }

    private function parseName(): PdfName
    {
        $start = ++$this->position;
        while ($this->position < $this->length && self::isRegular($this->data[$this->position])) {
            ++$this->position;
        }
        $raw = substr($this->data, $start, $this->position - $start);
        $name = (string) preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            static fn(array $match): string => \chr((int) hexdec($match[1])),
            $raw,
        );

        return new PdfName($name);
    }

    private function parseNumberOrReference(): PdfValue
    {
        $number = $this->readNumber();
        if (!\is_int($number) || $number < 0) {
            return new PdfNumber($number);
        }

        // « 12 0 R » : référence indirecte. Sinon, on revient après le premier nombre.
        $saved = $this->position;
        $this->skipWhitespace();
        if ($this->position < $this->length && $this->data[$this->position] >= '0' && $this->data[$this->position] <= '9') {
            $generation = $this->readNumber();
            if (\is_int($generation) && $generation >= 0 && $this->nextIsKeyword('R')) {
                ++$this->position;

                return new PdfReference($number, $generation);
            }
        }
        $this->position = $saved;

        return new PdfNumber($number);
    }

    private function readNumber(): int|float
    {
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $this->data, $match, 0, $this->position) !== 1) {
            throw new SyntaxError('Nombre invalide.');
        }
        $this->position += \strlen($match[0]);
        $text = $match[0];
        if (!str_contains($text, '.')) {
            $value = filter_var($text, FILTER_VALIDATE_INT);

            return $value === false ? (float) $text : $value;
        }

        return (float) $text;
    }

    private function parseKeyword(): PdfValue
    {
        $start = $this->position;
        while ($this->position < $this->length && self::isRegular($this->data[$this->position])) {
            ++$this->position;
        }
        $word = substr($this->data, $start, $this->position - $start);
        $keyword = PdfKeyword::tryFrom($word);
        if ($keyword === null) {
            $this->position = $start;

            throw new SyntaxError(sprintf('Mot-clé inattendu « %s ».', substr($word, 0, 20)));
        }

        return $keyword;
    }

    private static function isRegular(string $char): bool
    {
        return !str_contains(self::WHITESPACE, $char) && !str_contains(self::DELIMITERS, $char);
    }
}
