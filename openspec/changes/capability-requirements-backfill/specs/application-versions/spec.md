## ADDED Requirements

### Requirement: A maker rolls a version back to an earlier manifest (REQ-BQCV-015)

The version history SHALL offer a rollback on each earlier saved state of a version. After the maker confirms, the system SHALL write that earlier manifest to the version through the manifest endpoint, so the manifest and the version link are both kept, and the version history SHALL show the rollback as a new save.

#### Scenario: A maker undoes a broken page change

- **GIVEN** a development version whose latest save removed the page `permits`
- **WHEN** a maker opens the version history, picks the save before it and confirms the rollback
- **THEN** the version's manifest again holds the page `permits`
- **AND** the version history lists the rollback as the newest save

#### Scenario: Cancelling changes nothing

- **GIVEN** the same version
- **WHEN** the maker opens the rollback confirmation and cancels
- **THEN** the manifest is unchanged
