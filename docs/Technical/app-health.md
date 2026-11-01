# App health: performance and errors

A published app reports how it runs, and the app detail page shows it under
**Performance and errors** for the chosen version and window (7, 30 or 90 days).

## What a running app sends

Every five minutes, and when the browser tab is hidden, the app posts one
sample per page it used: the number of requests, failed requests (4xx and 5xx),
request durations in four buckets (under 250 ms, under 1 s, under 3 s, 3 s and
over), page loads, total load time, slow loads (3 s and over) and client
errors. A page load is the time from opening the page until the last request it
made in its first ten seconds came back.

A sample holds counts only. It has no user, no address with query values, no
request or response body and no error message.

## Who may send and who may read

- `POST /api/applications/{slug}/health-samples` takes samples from a signed-in
  user who may open that version, by the rule the manifest endpoint uses. The
  server works out the app and version itself, drops unknown keys, caps every
  count and the pages per post, and limits each user to 60 posts an hour.
- `GET /api/applications/{app}/versions/{version}/health?window=7d` answers to
  whoever may see that version's insights.

## How long samples are kept

A daily job deletes samples older than 30 days. An administrator can set
`health_retention_days` between 7 and 90:

```
occ config:app:set buildiq health_retention_days --value=60
```
