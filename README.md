# Semitexa Weave

`semitexa/weave`

The data layer for a knowledge and relationship graph: typed nodes and edges, stored through the ORM and upserted idempotently, so the same entity or relationship written twice is not duplicated. Semitexa OS uses it for its knowledge graph.

## Install

Not included by the installer. Add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/weave
bin/semitexa server:restart
bin/semitexa orm:sync
```

`semitexa/os` and `semitexa/cms` already require it.

## What it provides

- Tables `weave_node` and `weave_edge`.
- `GraphStoreInterface` (`Semitexa\Weave\Domain\Contract`), the persistence API, implemented by `GraphStore`; domain models `Node`, `Edge`, `Relation` and the `NodeKind` enum.
- `bin/semitexa weave:dedup`: finds near-duplicate nodes (same kind and content tokens) and merges them; dry run unless `--apply`.
- A `system:doctor` check that reports duplicate nodes, and a data patch that backfills the tenant id on existing rows.

## Documentation

Commands: https://semitexa.com/docs/reference/commands-weave

## License

MIT, see [LICENSE](LICENSE).
