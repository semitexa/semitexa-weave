<?php

declare(strict_types=1);

namespace Semitexa\Weave\Domain\Model;

/**
 * Well-known edge relation names. The relation vocabulary is intentionally OPEN
 * (a plain string on the edge) so the weaver/LLM can mint precise relations
 * ("reviewed", "blocks", "lives_in") without a schema change — these constants
 * are just the common ones consumers can rely on and normalise toward.
 */
final class Relation
{
    /** A person/actor works on a project. */
    public const WORKS_ON = 'works_on';
    /** Node is a component/child of another (task → project, note → project). */
    public const PART_OF = 'part_of';
    /** A thread/note/event references or names another node. */
    public const MENTIONS = 'mentions';
    /** Collaboration/participation with a person. */
    public const WITH = 'with';
    /** Node is about a topic. */
    public const ABOUT = 'about';
    /** Explicit reference/citation to another artefact (incl. files). */
    public const REFERENCES = 'references';
    /** A task/item is assigned to a person. */
    public const ASSIGNED_TO = 'assigned_to';
    /** A person attends an event. */
    public const ATTENDS = 'attends';
    /** Generic, LLM-inferred semantic relatedness (weakest edge). */
    public const RELATED_TO = 'related_to';

    private function __construct()
    {
    }

    /** Normalise a proposed relation name to a stable token (snake_case, trimmed). */
    public static function normalise(string $relation): string
    {
        $r = strtolower(trim($relation));
        $r = (string) preg_replace('/[\s-]+/', '_', $r);
        $r = (string) preg_replace('/[^a-z0-9_]/', '', $r);

        return $r !== '' ? $r : self::RELATED_TO;
    }
}
