# Retrofit — settings-and-observability

Describes observed behaviour of 10 methods under `settings-and-observability`
as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/Service/SettingsService.php::getSettings()
- lib/Service/SettingsService.php::updateSettings()
- lib/Service/SettingsService.php::loadConfiguration()
- lib/Service/SettingsService.php::reloadConfiguration()
- lib/Controller/SettingsController.php::index()
- lib/Controller/SettingsController.php::create()
- lib/Controller/SettingsController.php::load()
- lib/Repair/InitializeSettings.php::run()
- lib/Controller/HealthController.php::index()
- lib/Controller/MetricsController.php::index()

## Approach
- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behaviour (not aspirational)
- Notes section surfaces the empty metrics payload and the authenticated-probe quirk

Source: openspec/coverage-report (gate-16 spec-coverage) generated 2026-05-25.
See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
