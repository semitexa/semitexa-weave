<?php

declare(strict_types=1);

namespace Semitexa\Weave\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Model\TitleKey;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Find (and with --apply, merge) near-duplicate nodes: same kind, same
 * content-token set ({@see TitleKey::tokenSet}) — the deterministic sweep for
 * duplicates minted before the upsert guard existed, or by hand. Each cluster
 * merges into its OLDEST node (its title is the canonical one; edges repoint,
 * properties merge). Dry-run by default.
 */
#[AsCommand(
    name: 'weave:dedup',
    description: 'Find and merge near-duplicate Weave nodes (same kind + content-token set). Dry-run unless --apply.',
)]
final class WeaveDedupCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Actually merge (default is a dry-run report).');
        $this->addOption('merge', null, InputOption::VALUE_REQUIRED, 'Explicit targeted merge: <keepId>:<dropId> (works across kinds — the kept node\'s kind wins).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');

        // Explicit targeted merge — the escape hatch for cross-kind duplicates
        // the automatic sweep deliberately refuses to touch.
        $pair = (string) ($input->getOption('merge') ?? '');
        if ($pair !== '') {
            [$keepId, $dropId] = array_pad(explode(':', $pair, 2), 2, '');
            if ($keepId === '' || $dropId === '') {
                $output->writeln('<error>--merge expects <keepId>:<dropId>.</error>');

                return Command::FAILURE;
            }
            $this->graph->mergeNodes($keepId, $dropId);
            $output->writeln('Merged ' . $dropId . ' into ' . $keepId . '.');

            return Command::SUCCESS;
        }

        $nodes = $this->graph->graph(500)['nodes'];

        /** @var array<string, list<\Semitexa\Weave\Domain\Model\Node>> $clusters */
        $clusters = [];
        foreach ($nodes as $node) {
            $clusters[$node->kind->value . "\0" . TitleKey::tokenSet($node->title)][] = $node;
        }

        $found = 0;
        foreach ($clusters as $cluster) {
            if (count($cluster) < 2) {
                continue;
            }
            $found++;
            usort($cluster, static fn ($a, $b): int => strcmp($a->id, $b->id)); // UUIDv7 → oldest first
            $keep = array_shift($cluster);
            $output->writeln(sprintf(
                '<info>%s</info> "%s" ← %s',
                $apply ? 'merging into' : 'would merge into',
                $keep->title,
                implode(', ', array_map(static fn ($n): string => '"' . $n->title . '"', $cluster)),
            ));
            if ($apply) {
                foreach ($cluster as $dup) {
                    $this->graph->mergeNodes($keep->id, $dup->id);
                }
            }
        }

        // Cross-kind suspects: same token set, DIFFERENT kinds. The model's kind
        // assignment is noisy for the same concept ('Semitexa documentation' as
        // project vs 'documentation for Semitexa' as note), but auto-merging
        // across kinds would also merge person "Anna" into project "Anna" —
        // report only; resolve with --merge <keepId>:<dropId>.
        $byTokens = [];
        foreach ($nodes as $node) {
            $byTokens[TitleKey::tokenSet($node->title)][] = $node;
        }
        $suspects = 0;
        foreach ($byTokens as $group) {
            $kinds = array_unique(array_map(static fn ($n): string => $n->kind->value, $group));
            if (count($group) < 2 || count($kinds) < 2) {
                continue;
            }
            $suspects++;
            $output->writeln('<comment>cross-kind suspect:</comment> ' . implode(' | ', array_map(
                static fn ($n): string => $n->kind->value . ' "' . $n->title . '" (' . $n->id . ')',
                $group,
            )));
        }

        $output->writeln($found === 0
            ? 'No same-kind near-duplicate clusters found.'
            : sprintf('%d cluster(s) %s.', $found, $apply ? 'merged' : 'found (dry-run; use --apply)'));
        if ($suspects > 0) {
            $output->writeln(sprintf('%d cross-kind suspect group(s) — resolve with --merge <keepId>:<dropId>.', $suspects));
        }

        return Command::SUCCESS;
    }
}
