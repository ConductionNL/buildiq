<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Script steps

Use a script step when the built-in steps of an automation cannot do what you
need: join two fields into one, reshape a list, compute a value from several
fields. It is the action *Run a script* in the automation composer
(Automations page, *New automation* or *Edit*).

A script is JavaScript: the body of a function that receives the flow's items
and returns items. Each item is `{ json, binary }`, so a script that joins a
first and last name looks like this:

```js
return items.map((item) => ({
	...item,
	json: { ...item.json, naam: item.json.voornaam + ' ' + item.json.achternaam },
}))
```

Each step also has a **time limit** (1 to 30 seconds, 10 by default) and a
**note** saying in words what the script does, for whoever reads the
automation later.

## Where the script runs

Only in OpenRegister's code runner, a separate container. Buildiq never runs
the script: not when you save, not in the dry run, and not in your browser.
The step compiles to OpenRegister's code step (`openregister.code`). The dry
run lists it as "runs in the code runner" and does not execute it.

Script steps can be added to automations started by hand, by an incoming
e-mail or by a webhook, the triggers that run as an OpenRegister flow.

## What a script can reach

Only the items it receives. The code runner has no Nextcloud, no database, no
file system and no network. Buildiq declares no network access for the step.
A script that runs past its time limit, or fails, is stopped, and the step's
error handling decides what happens next.

The items a script receives can contain personal data. They leave Nextcloud
only for the code runner, and from there go nowhere else.

## Who may write a script

- **An administrator turns script steps on.** In Buildiq's admin settings,
  *Allow script steps in automations* is off by default. While it is off, *Run
  a script* is disabled with "Script steps are turned off for this instance."
  Turning it off later disables every automation that holds a script step and
  records that reason on each one.
- **The code runner must be installed.** Without it OpenRegister's node
  catalogue has no code step. *Run a script* is then disabled with "The code
  runner is not installed, so scripts cannot run.", and an automation that
  already holds one cannot be compiled.
- **Only app owners change scripts.** Adding, changing or removing a script
  step needs the `owners` role on the app. Instance administrators get no
  exception. An editor can read the code. A save that changes it is refused
  with "Only app owners can change a script step." The automation records who
  last changed a script (`provenance.scriptAuthor`), so every run can be traced
  to a person.
