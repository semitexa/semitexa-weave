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
    case File = 'file';
    case Place = 'place';
    case Org = 'org';
    case Thread = 'thread';

    /** Coerce a loose (e.g. LLM-produced) string to a kind, or null if unknown. */
    public static function tryFromLoose(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
