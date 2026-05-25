<?php

declare(strict_types=1);

namespace SqlcPhp\Inflector;

/**
 * Thin wrapper around doctrine/inflector for singularising table names.
 *
 * When doctrine/inflector is available (installed via composer or system package),
 * it is used for accurate language-aware singularisation.
 *
 * When it is NOT available (e.g. in environments where only the bare source is
 * checked out without dependencies), the class falls back to a built-in
 * rule set that handles common English patterns. The fallback is transparent —
 * no exceptions are thrown.
 *
 * Supported languages (when doctrine/inflector is present):
 *   english | spanish | french | portuguese | norwegian-bokmal | turkish
 */
class InflectorService
{
    /** doctrine\Inflector\Inflector instance or null when unavailable */
    private ?object $inflector;

    public function __construct(string $language = 'english')
    {
        $this->inflector = $this->buildInflector($language);
    }

    /**
     * Singularise a word using the configured language.
     * e.g. "users" → "user", "analyses" → "analysis", "usuarios" → "usuario"
     */
    public function singularize(string $word): string
    {
        $lower = strtolower($word);

        if ($this->inflector !== null) {
            return $this->inflector->singularize($lower);
        }

        return $this->fallbackSingularize($lower);
    }

    /**
     * Convert to PascalCase.
     * e.g. "user_role" → "UserRole", "user" → "User"
     */
    public function toPascalCase(string $word): string
    {
        $parts = preg_split('/[_\s]+/', strtolower($word)) ?: [$word];
        return implode('', array_map('ucfirst', $parts));
    }

    // -------------------------------------------------------------------------

    private function buildInflector(string $language): ?object
    {
        // Load doctrine/inflector if not already loaded
        foreach ([
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            '/usr/share/php/Doctrine/Inflector/autoload.php',
        ] as $path) {
            if (file_exists($path) && !class_exists('Doctrine\Inflector\InflectorFactory')) {
                require_once $path;
            }
        }

        if (!class_exists('Doctrine\Inflector\InflectorFactory')) {
            return null;
        }

        // Map config string → Language constant value
        $langMap = [
            'english'          => 'english',
            'spanish'          => 'spanish',
            'french'           => 'french',
            'portuguese'       => 'portuguese',
            'norwegian-bokmal' => 'norwegian-bokmal',
            'norwegian_bokmal' => 'norwegian-bokmal',
            'turkish'          => 'turkish',
        ];

        $lang = $langMap[strtolower($language)] ?? 'english';

        if ($lang === 'english') {
            return \Doctrine\Inflector\InflectorFactory::create()->build();
        }

        return \Doctrine\Inflector\InflectorFactory::createForLanguage($lang)->build();
    }

    /**
     * Built-in fallback for English singularisation.
     * Used when doctrine/inflector is not available.
     */
    private function fallbackSingularize(string $word): string
    {
        $irregulars = [
            'people'   => 'person',
            'children' => 'child',
            'geese'    => 'goose',
            'men'      => 'man',
            'women'    => 'woman',
            'mice'     => 'mouse',
            'teeth'    => 'tooth',
            'feet'     => 'foot',
            'statuses' => 'status',
            'aliases'  => 'alias',
        ];

        if (isset($irregulars[$word])) return $irregulars[$word];

        if (str_ends_with($word, 'ies'))  return substr($word, 0, -3) . 'y';
        if (str_ends_with($word, 'ses'))  return substr($word, 0, -2);
        if (str_ends_with($word, 'xes'))  return substr($word, 0, -2);
        if (str_ends_with($word, 'ches')) return substr($word, 0, -2);
        if (str_ends_with($word, 'shes')) return substr($word, 0, -2);
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }

        return $word;
    }
}
