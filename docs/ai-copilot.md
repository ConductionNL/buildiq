---
sidebar_position: 8
description: The AI copilot turns a natural-language brief into a reviewable, approvable builder plan — the prompt-to-app path in OpenBuild.
---

# AI Copilot

The AI copilot is OpenBuild's prompt-to-app surface: describe the app you
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
   OpenBuild's MCP tools already expose (create app, promote version, upsert
   schema, upsert page, add widget, upsert menu item, list apps, get
   manifest). The AI's reply is parsed into a plan: a short summary plus an
   ordered list of steps. **Nothing is written during this step.**
2. **Review** — every step is validated against that operation's argument
   schema, and cap-checked so no proposed change would blow past a
   manifest's size or page/widget/menu-item limits. The predicted manifest
   is shown as a diff. If validation fails, Approve stays disabled and you
   see why.
3. **Approve** — only on your explicit action does OpenBuild execute the
   plan. Execution runs through the exact same handler code the builder's
   MCP tools use — the same permission checks, the same locking, the same
   caps. There is no separate, less-checked path for AI-driven changes.

## Atomicity guarantee

An approved plan is applied step by step. If any step fails partway
through, OpenBuild restores every manifest it had touched to its
pre-plan snapshot, and deletes an application the plan itself created (so
you're never left with a half-built app you didn't ask for). This is
compensation-based, not a database transaction — OpenRegister has no
cross-object transactions — so the guarantee is precisely scoped: **a
failed plan leaves no plan-created state behind.** Every write still goes
through the same locked, validated handler path, so nothing is ever
silently corrupted; worst case is a visible, deletable draft you can
remove by hand.

## Provider setup (admins)

The copilot rides Nextcloud's built-in **Task Processing** API, so it
works with whatever text-generation provider you've configured for your
instance — a local model, an EU-hosted one, or one of the bundled
Nextcloud AI apps. OpenBuild never talks to a vendor directly and never
names a model.

- Requires **Nextcloud 30 or newer** (Task Processing shipped in NC 30).
- Configure a `TextToText` provider under **Administration settings → Artificial
  intelligence**.
- The AI Chat Companion (the free-form assistant available elsewhere in
  Nextcloud) shares the same provider configuration but is a different,
  independent surface — the copilot's deterministic plan/approve flow is
  specific to OpenBuild.

### Degradation without a provider

When no provider is configured (or the server predates NC 30), the copilot
is simply absent: the wizard's "Generate with AI" button and the builder's
panel toggle are both hidden. Nextcloud administrators additionally see a
small hint in the wizard pointing at the AI provider settings; everyone
else sees no trace of the feature at all.

## Permissions

- **Editing an existing app**: you need an owner or editor role on that
  app — the same bar as any other builder write. Nextcloud administrators
  get the same audited bypass the builder's MCP tools already have.
- **Creating a new app**: any authenticated user can generate and confirm a
  new app; you become its owner, exactly like the manual creation wizard.
- **Hybrid apps** (installed real apps OpenBuild layers customisation on
  top of) are out of scope for the copilot entirely — it only edits virtual
  apps built from scratch in OpenBuild.

## What it will not do

- It never applies a change without your explicit approval — there is no
  "auto-apply" mode and no autonomous multi-turn agent loop.
- It cannot generate or run arbitrary code — every proposed step is one of
  the fixed, allow-listed builder operations.
- It cannot touch an installed real app's manifest (hybrid apps are
  rejected as a target).
