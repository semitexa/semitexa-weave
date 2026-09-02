<?php

declare(strict_types=1);

namespace Semitexa\Weave\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * One store, two graphs — the person's world and the structure of the sites
 * they manage — and the seam between them is this vocabulary split.
 */
final class NodeKindVocabularyTest extends TestCase
{
    #[Test]
    public function the_three_site_kinds_are_marked_as_site_structure(): void
    {
        self::assertTrue(NodeKind::Site->isSiteStructure());
        self::assertTrue(NodeKind::Page->isSiteStructure());
        self::assertTrue(NodeKind::Collection->isSiteStructure());
    }

    #[Test]
    public function nothing_from_the_personal_world_is(): void
    {
        foreach ([NodeKind::Project, NodeKind::Person, NodeKind::Note, NodeKind::Task, NodeKind::Event, NodeKind::File] as $kind) {
            self::assertFalse($kind->isSiteStructure(), $kind->value . ' should belong to the personal graph.');
        }
    }

    #[Test]
    public function the_personal_vocabulary_offers_no_site_kinds(): void
    {
        // This list is handed to a language model as the words it may use when
        // it infers nodes from conversation. Told that `page` and `collection`
        // exist, it will file someone's holiday plans under them.
        $personal = NodeKind::personalKinds();

        self::assertNotContains(NodeKind::Site, $personal);
        self::assertNotContains(NodeKind::Page, $personal);
        self::assertNotContains(NodeKind::Collection, $personal);
        self::assertContains(NodeKind::Project, $personal);
        self::assertCount(count(NodeKind::cases()) - 3, $personal);
    }

    #[Test]
    public function the_new_kinds_still_coerce_from_a_loose_string(): void
    {
        self::assertSame(NodeKind::Collection, NodeKind::tryFromLoose('  Collection '));
        self::assertNull(NodeKind::tryFromLoose('grid'));
    }
}
