<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Model;

/**
 * Title normalisation for node identity.
 *
 * Two keys, two purposes:
 *  - exact(): the historical dedup key — lowercased, whitespace-collapsed.
 *  - tokenSet(): NEAR-duplicate identity. Different phrasings of the same
 *    thing ("Semitexa documentation" / "documentation for Semitexa") reduce
 *    to the same sorted set of content words, so the store can converge them
 *    without an LLM judge. Deterministic and language-tolerant: punctuation
 *    is stripped, a small multilingual stopword list is dropped, the rest is
 *    sorted. A title made ONLY of stopwords falls back to exact() so such
 *    titles don't all collide with each other.
 */
final class TitleKey
{
    /** Function words that carry no identity across phrasings. */
    private const STOPWORDS = [
        // en
        'a', 'an', 'the', 'for', 'of', 'to', 'in', 'on', 'at', 'and', 'or', 'with', 'my',
        // uk
        'для', 'з', 'зі', 'на', 'в', 'у', 'і', 'й', 'та', 'або', 'про', 'мій', 'моя', 'моє', 'мої',
    ];

    public static function exact(string $title): string
    {
        $key = mb_strtolower(trim($title));
        $key = (string) preg_replace('/\s+/u', ' ', $key);

        return mb_substr($key, 0, 255);
    }

    public static function tokenSet(string $title): string
    {
        $normalized = mb_strtolower(trim($title));
        $normalized = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_diff($tokens, self::STOPWORDS)));
        if ($tokens === []) {
            return self::exact($title);
        }
        sort($tokens);

        return mb_substr(implode(' ', $tokens), 0, 255);
    }
}
