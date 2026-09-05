<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Enum;

/**
 * The kind of thing a graph node represents. Projects are the gravitational
 * centre of the Weave; everything else (people, topics, the concrete artefacts
 * — notes/tasks/events/files — and the world around the work) hangs off them.
 *
 * Deliberately a bounded, meaningful set: new kinds are added here intentionally,
 * not minted ad-hoc, so views and queries can rely on the vocabulary. Edge
 * relations, by contrast, are an open string vocabulary ({@see \Semitexa\Weave\Domain\Relation}).
 *
 * The last three describe a managed site rather than the person using it: a
 * {@see self::Site} holds {@see self::Page}s and {@see self::Collection}s, and
 * the distinction between those two is the whole navigation model — a page has
 * its own address and its own content, so touching it opens an editor, while a
 * collection is a stack of same-shaped records, so touching it opens a grid.
 * Storing that in `properties` instead would leave the graph unable to answer
 * the one question every view asks of a node: what happens when I open it.
 */
enum NodeKind: string
{
    case Project = 'project';
    case Person = 'person';
    case Topic = 'topic';
    case Note = 'note';
    case Task = 'task';
    case Event = 'event';
    case App = 'app';
    case Folder = 'folder';
    case File = 'file';
    case Place = 'place';
    case Org = 'org';
    case Thread = 'thread';

    /**
     * Something the person is working towards — "move to Portugal next year",
     * "learn Rust", "finish the renovation before winter".
     *
     * A goal is not an entity in their world the way a person or a place is:
     * nothing in the rest of this vocabulary could hold one, so an intention
     * either became a vague `topic` or was dropped. Preferences and habits, by
     * contrast, need no kind of their own — they are predicates on a thing that
     * already exists ("self interested_in гітара").
     */
    case Goal = 'goal';

    /** A managed site — the root the rest of its structure hangs from. */
    case Site = 'site';

    /** One addressable page: its own content, edited directly. */
    case Page = 'page';

    /** A stack of same-shaped records (events, news, media): listed, not edited in place. */
    case Collection = 'collection';

    /**
     * True for the three kinds that describe a managed site rather than the
     * person using the OS.
     *
     * The separation is not cosmetic: the conversational weaver offers its
     * vocabulary to a language model, and a model told that `page` and
     * `collection` exist will happily file someone's holiday plans under them.
     * The two graphs share a store; they must not share a vocabulary.
     */
    public function isSiteStructure(): bool
    {
        return match ($this) {
            self::Site, self::Page, self::Collection => true,
            default => false,
        };
    }

    /**
     * The vocabulary of the personal graph — everything the weaver may infer.
     *
     * @return list<self>
     */
    public static function personalKinds(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => !$kind->isSiteStructure(),
        ));
    }

    /** Coerce a loose (e.g. LLM-produced) string to a kind, or null if unknown. */
    public static function tryFromLoose(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
