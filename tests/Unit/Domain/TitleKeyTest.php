<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Weave\Domain\Model\TitleKey;

final class TitleKeyTest extends TestCase
{
    #[Test]
    public function rephrasings_share_one_token_set(): void
    {
        self::assertSame(
            TitleKey::tokenSet('Semitexa documentation'),
            TitleKey::tokenSet('documentation for Semitexa'),
        );
        self::assertSame(
            TitleKey::tokenSet('документація для Semitexa'),
            TitleKey::tokenSet('Semitexa документація'),
        );
    }

    #[Test]
    public function different_things_stay_apart(): void
    {
        self::assertNotSame(TitleKey::tokenSet('Semitexa documentation'), TitleKey::tokenSet('Semitexa'));
        self::assertNotSame(TitleKey::tokenSet('coastal house'), TitleKey::tokenSet('coastal garden'));
    }

    #[Test]
    public function punctuation_case_and_duplicate_words_collapse(): void
    {
        self::assertSame(TitleKey::tokenSet('Coastal-house renovation!'), TitleKey::tokenSet('renovation coastal house'));
        self::assertSame(TitleKey::tokenSet('anna anna'), TitleKey::tokenSet('Anna'));
    }

    #[Test]
    public function stopword_only_titles_fall_back_to_exact_and_do_not_collide(): void
    {
        self::assertSame('the', TitleKey::tokenSet('The'));
        self::assertNotSame(TitleKey::tokenSet('the'), TitleKey::tokenSet('for'));
    }

    #[Test]
    public function exact_key_is_lowercased_and_whitespace_collapsed(): void
    {
        self::assertSame('semitexa docs', TitleKey::exact('  Semitexa   Docs '));
    }
}
