<?php

declare(strict_types=1);

namespace PolygonLab\FactureElectronique\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chaque script de examples/ figure dans le README, suivi de sa sortie exacte : les exemples de la
 * documentation fonctionnent réellement.
 */
final class ReadmeTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        foreach (glob(self::ROOT . '/examples/*.php') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('examples')]
    public function testTheExampleIsInTheReadmeWithItsOutput(string $path): void
    {
        $code = rtrim(str_replace(
            "require __DIR__ . '/../vendor/autoload.php';",
            "require __DIR__ . '/vendor/autoload.php';",
            self::read($path),
        ), "\n");
        $readme = self::read(self::ROOT . '/README.md');

        $block = "```php\n" . $code . "\n```";
        $position = strpos($readme, $block);
        self::assertNotFalse($position, 'exemple absent du README : ' . basename($path));

        if (preg_match('/```text\n(.*?)\n```/s', $readme, $matches, 0, $position + \strlen($block)) !== 1) {
            self::fail('sortie absente du README : ' . basename($path));
        }
        self::assertSame($matches[1], rtrim(self::execute($path), "\n"));
    }

    public function testTheQuickStartComesFirst(): void
    {
        $readme = self::read(self::ROOT . '/README.md');
        $start = strpos($readme, "## Démarrage rapide\n");
        self::assertNotFalse($start);

        self::assertStringContainsString(
            "->extractFromFile(__DIR__ . '/facture-exemple.pdf')",
            substr($readme, $start, (int) strpos($readme, '## Utilisation') - $start),
        );
    }

    private static function execute(string $path): string
    {
        ob_start();
        try {
            (static function (string $script): void {
                require $script;
            })($path);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, 'fichier illisible : ' . $path);

        return $content;
    }
}
