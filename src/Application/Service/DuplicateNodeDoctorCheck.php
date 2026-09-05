<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Service;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;
use Semitexa\Weave\Domain\Model\Node;
use Semitexa\Weave\Domain\Model\TitleKey;

/**
 * Report the one class of duplicate the graph cannot clean up by itself: the
 * same title recorded under two different kinds.
 *
 * `weave:dedup` merges same-kind clusters and deliberately refuses cross-kind
 * ones, because person "Anna" and project "Anna" are genuinely two things. That
 * refusal is right, and it means an automated sweep can never resolve these —
 * measured on a live graph, the sweep found zero same-kind clusters while every
 * actual duplicate in it was cross-kind. So the sweep is not the answer; the
 * operator is, and this is how they find out there is something to decide.
 *
 * A warning, never a failure: two things may legitimately share a name.
 */
#[AsDoctorCheck(name: 'weave.duplicate-nodes', package: 'semitexa/weave')]
final class DuplicateNodeDoctorCheck implements DoctorCheckInterface
{
    /** Bounded scan — the doctor is a fast probe, not a report. */
    private const SCAN_LIMIT = 500;

    public function run(): DoctorResult
    {
        try {
            /** @var list<Node> $nodes */
            $nodes = (new GraphStore())->graph(self::SCAN_LIMIT)['nodes'];
        } catch (\Throwable $e) {
            // No graph yet is not a problem to report; an unreadable one is.
            return DoctorResult::warn(
                'Could not read the Weave graph: ' . $e->getMessage(),
                'Run `bin/semitexa orm:sync` if the weave tables are missing.',
            );
        }

        return $this->evaluate($nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    public function evaluate(array $nodes): DoctorResult
    {
        $byTokens = [];
        foreach ($nodes as $node) {
            $byTokens[TitleKey::tokenSet($node->title)][] = $node;
        }

        $suspects = [];
        foreach ($byTokens as $group) {
            $kinds = array_unique(array_map(static fn (Node $n): string => $n->kind->value, $group));
            if (count($group) > 1 && count($kinds) > 1) {
                $suspects[] = $group[0]->title . ' (' . implode(', ', $kinds) . ')';
            }
        }

        if ($suspects === []) {
            return DoctorResult::pass('No cross-kind duplicate nodes in the Weave graph.');
        }

        return DoctorResult::warn(
            count($suspects) . ' title(s) recorded under more than one kind, so the assistant reads them '
            . 'back as separate things: ' . implode('; ', array_slice($suspects, 0, 5))
            . (count($suspects) > 5 ? '; …' : ''),
            'Review with `bin/semitexa weave:dedup`, then merge the ones that are the same thing: '
            . '`bin/semitexa weave:dedup --merge <keepId>:<dropId>`.',
        );
    }
}
