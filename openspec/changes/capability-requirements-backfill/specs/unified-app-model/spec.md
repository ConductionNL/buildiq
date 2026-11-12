## ADDED Requirements

### Requirement: Customised installed apps are listed beside built apps and marked hybrid (REQ-BQCV-016)

The apps overview SHALL list every hybrid Application (an installed app the organisation customised) together with the virtual apps it built, SHALL mark each hybrid entry with a visible hybrid label, and SHALL show the number of hybrid apps beside the number of built apps.

#### Scenario: A customised installed app shows in the overview

- **GIVEN** one virtual app and one hybrid app that customises an installed app
- **WHEN** a maker opens the apps overview
- **THEN** both apps are listed
- **AND** only the hybrid app carries the hybrid label, and the hybrid count reads 1
