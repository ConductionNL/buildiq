# Spec: built-app-health-monitoring

## Purpose

A maker and an administrator see how a published app is doing: how often its
requests fail, how fast its pages load, and which pages are slow. Running apps
send counts, never content; buildiq keeps them for 30 days, shows them on the
app detail page and exposes them to Prometheus.

## ADDED Requirements

### Requirement: A running app reports counts, not content (REQ-BQDM-004)

A published app SHALL post a health sample every five minutes and when its page
is hidden, holding per page: requests, failures with a 4xx and a 5xx status,
request durations in four buckets (under 250 ms, under 1 s, under 3 s, 3 s and
over), page loads, total load time and client errors. A sample SHALL NOT hold a
user id, a request or response body, a URL query value or an error message. A
browser with nothing to report SHALL post nothing.

#### Scenario: A case handler's session produces one sample

- **GIVEN** a case handler who opens the `permits` list of `vergunningen` three times in five minutes, with one request answering 500
- **WHEN** the five minutes pass
- **THEN** one sample is posted for page `permits` with three loads, one 5xx failure and the durations in their buckets, and it carries no user id and no error text

### Requirement: The server scopes and bounds samples (REQ-BQDM-005)

The server SHALL accept a sample only from a signed-in user who may open that app
version, SHALL derive the app and version itself, SHALL drop unknown keys, SHALL
cap each count and the number of pages per sample, SHALL rate-limit posts, and
SHALL delete samples older than the retention setting (30 days by default).

#### Scenario: A user without access cannot post

- **GIVEN** a user who may not open the development version of `vergunningen`
- **WHEN** they post a sample for it
- **THEN** the server answers as for an unknown app and stores nothing

#### Scenario: Old samples are removed

- **GIVEN** samples from 31 days ago and from yesterday
- **WHEN** the daily cleanup runs
- **THEN** the old samples are deleted and yesterday's remain

### Requirement: The app detail page shows the app's health (REQ-BQDM-006)

The app detail page SHALL show a "Health" section for the selected version and
window (7, 30 or 90 days): failure rate, average page load time, share of
requests over 1 s, and the five slowest pages. It SHALL be visible to exactly the
users who may see that version's insights.

#### Scenario: A maker finds the slow page

- **GIVEN** the production version of `vergunningen` with a week of samples, where page `permit-detail` averages 2.4 s to load
- **WHEN** a maker with the viewer role opens the app detail page with the 7-day window
- **THEN** the Health section lists `permit-detail` first among the slowest pages with 2.4 s

### Requirement: Operators read the same numbers as metrics (REQ-BQDM-007)

Buildiq's AppHost metrics endpoint SHALL expose request totals, request failure
totals and client error totals per app and version, summed from the stored
samples. The endpoint SHALL stay administrator only.

#### Scenario: An administrator scrapes the metrics

- **GIVEN** stored samples for `vergunningen` production
- **WHEN** an administrator requests buildiq's metrics endpoint
- **THEN** the output has `buildiq_app_requests_total` with the labels `app="vergunningen"` and `version="production"`
