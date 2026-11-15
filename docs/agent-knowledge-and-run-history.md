# Agent knowledge and run history

A builder agent is a named copilot for one app, with its own instructions and
a fixed set of tools. This page covers two things you can do with one: give
it documents to answer from, and read back what it did in each run.

## Open the agents of an app

1. Open the app's detail page.
2. Open the actions menu and choose **Agents**.

The Agents page opens with that app selected and lists its agents. The same
people who see **Automations** in that menu see **Agents**: the app's owners
and editors.

## Give an agent knowledge

Knowledge is a set of files attached to the agent. Before the agent plans, it
reads them, after its instructions and in the order they are listed. Use it
for things the agent should follow every time, such as a style guide for form
labels or the naming rules of your organisation.

1. On the Agents page, edit the agent.
2. Under **Knowledge**, choose **Add knowledge file** and pick a file.
3. Save the agent.

Plain text (`.txt`) and Markdown (`.md`) files can be added. PDF and Word
files are not read yet.

All the knowledge of one agent together holds at most 40,000 characters. The
section shows how much room is left. A file that does not fit is refused,
and the message says how many characters of room are left, so you can shorten
the file or remove another one.

To take a file out, choose **Remove** next to it and save the agent.

### Who can read the files

The files are stored on the agent itself. Only the people who may run the
agent, the owners and editors of its app, can read them. An agent's knowledge
is never read for a person without that access: they get no plan at all.

## Read what an agent did

Every run of an agent is kept in its run history on the Agents page. A run
shows, from top to bottom:

- **Model steps**: each call to the language model for that turn. A step shows
  its attempt number, how long it took and how it ended:
  - **Attempt 1** is the plan.
  - **Attempt 1, repair needed** means the first answer could not be used. The
    reason is shown, and the agent asked the model once more.
  - **Attempt 2** is that repair. There is never a third attempt.
  - **no usable answer** or **the model did not answer** means the run stopped
    there.

  The model's own answer is behind **Model answer**.
- **Knowledge used**: the files that went into the prompt.
- The tool calls the plan made, with their arguments and results, as before.

When the steps of a run could not be found any more, for example because the
plan was applied long after it was made, the run says so instead of showing
steps. Its tool calls are still kept.

The run history, like the knowledge, is visible to the owners and editors of
the agent's app only.

Token counts are not shown: Nextcloud's text processing does not report them.
