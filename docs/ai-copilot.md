---
sidebar_position: 8
description: The AI copilot turns a natural-language brief into a reviewable, approvable builder plan — the prompt-to-app path in Buildiq.
---

# AI Copilot

The AI copilot is Buildiq's prompt-to-app surface: describe the app you
want in a sentence or two, review the concrete operations the AI proposes,
and approve before anything is created or changed. It never mutates your
app silently.

## What it does

- **In the creation wizard** — Step 1 offers a **Generate with AI** button.
  Describe the app, review the proposed schemas, pages, and menu items, then
  confirm to create the app and jump straight into it.
- **In the page designer** — a toolbar toggle opens a chat-style side panel
  scoped to the app and version you're editing. Ask it to add a page, a
  widget, or a menu item; it proposes the operations plus a before/after
  manifest diff. Approve to apply, or discard — nothing happens until you
  say so.

## The plan / review / approve model

1. **Plan** — your brief is sent to the configured AI provider with a
   constrained prompt that only knows about the eight builder operations
   Buildiq's MCP tools already expose (create app, promote version, upsert
   schema, upsert page, add widget, upsert menu item, list apps, get
   manifest). The AI's reply is parsed into a plan: a short summary plus an
   ordered list of steps. **Nothing is written during this step.**
2. **Review** — every step is validated against that operation's argument
   schema, and cap-checked so no proposed change would blow past a
   manifest's size or page/widget/menu-item limits. The predicted manifest
   is shown as a diff. If validation fails, Approve stays disabled and you
   see why.
3. **Approve** — only on your explicit action does Buildiq execute the
   plan. Execution runs through the exact same handler code the builder's
   MCP tools use — the same permission checks, the same locking, the same
   caps. There is no separate, less-checked path for AI-driven changes.

## Atomicity guarantee

An approved plan is applied step by step. If a step fails partway
through, Buildiq restores every manifest it had touched, and deletes the
application the plan created. **A failed plan leaves nothing behind.**
That includes the registers the app created and the schemas inside them:
the plan made those seconds earlier, so there is no data of yours to
lose. You do not have to go looking for leftovers.

This is compensation, not a database transaction. OpenRegister has no
transaction across objects, so Buildiq undoes its own writes instead. If
something resists deletion, the error response names it, and you can
remove that one thing yourself.

## Provider setup (admins)

The copilot rides Nextcloud's built-in **Task Processing** API, so it
works with whatever text-generation provider you've configured for your
instance — a local model, an EU-hosted one, or one of the bundled
Nextcloud AI apps. Buildiq never talks to a vendor directly and never
names a model.

- Requires **Nextcloud 30 or newer** (Task Processing shipped in NC 30).
- Configure a `TextToText` provider under **Administration settings → Artificial
  intelligence**.
- The AI Chat Companion (the free-form assistant available elsewhere in
  Nextcloud) shares the same provider configuration but is a different,
  independent surface — the copilot's deterministic plan/approve flow is
  specific to Buildiq.

### Where the model call runs

A provider that runs inside Nextcloud answers in the same request, so a plan
comes back as fast as the model does. A provider that runs outside Nextcloud
(an ExApp) cannot, so the request waits for a task processing worker. Keep one
running, or the copilot gives up after two minutes and cancels its task.

### Degradation without a provider

When no provider is configured (or the server predates NC 30), the copilot
is simply absent: the wizard's "Generate with AI" button and the builder's
panel toggle are both hidden. Nextcloud administrators additionally see a
small hint in the wizard pointing at the AI provider settings; everyone
else sees no trace of the feature at all.

A provider that is registered but cannot answer, for instance one with no
model configured behind it, is a different case: the copilot is offered, the
call fails, and the panel says so and repeats what the provider said. It does
not ask you to rephrase a brief the model never saw.

### Which version a proposal writes to

The builder tools default to the `development` version. The page designer
tells the copilot which version you have open, so a proposal lands on the
version you are editing. The panel says which one that is, above the
conversation.

## Permissions

- **Editing an existing app**: you need an owner or editor role on that
  app — the same bar as any other builder write. Nextcloud administrators
  get the same audited bypass the builder's MCP tools already have.
- **Creating a new app**: any authenticated user can generate and confirm a
  new app; you become its owner, exactly like the manual creation wizard.
- **Hybrid apps** (installed real apps Buildiq layers customisation on
  top of) are out of scope for the copilot entirely — it only edits virtual
  apps built from scratch in Buildiq.

## What it will not do

- It never applies a change without your explicit approval — there is no
  "auto-apply" mode and no autonomous multi-turn agent loop.
- It cannot generate or run arbitrary code — every proposed step is one of
  the fixed, allow-listed builder operations.
- It cannot touch an installed real app's manifest (hybrid apps are
  rejected as a target).
