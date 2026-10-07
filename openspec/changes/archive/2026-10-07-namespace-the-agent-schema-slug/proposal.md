# Namespace the agent schema slug

## Why

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `agent` was answered for by hermiq's agent as readily
as by this app's. A fleet audit on 2026-09-05 found eighteen slugs in that
state; this is one.

They are not two views of one record. hermiq's `Agent` is the AI agent: 41
fields of model, tools and Talk binding. This app's is a 6-field workspace
pointer for the agents bound to a generated application. They share two fields,
so the pair is renamed apart rather than folded onto one owner.

## What changes

This app's slug becomes `buildAgent`. The descriptor KEY stays `Agent`, because
this app's register lists schemas by slug and `$ref`s them by key, so the two
are not interchangeable here.

`FlowAndAgentExportBundler::HERMIQ_AGENT_SCHEMA` keeps naming `agent`. That is
hermiq's slug and it does not move. The two constants sitting side by side with
the same value was the clearest sign the slug was carrying two meanings.

A repair step renames the row before the register import, accepting both
`buildiq` and `openbuild` as the owning application because this app's schemas
are mid-migration between the two.
