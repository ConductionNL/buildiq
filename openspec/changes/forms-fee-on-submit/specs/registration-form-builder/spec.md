# registration-form-builder Specification (delta)

## ADDED Requirements

### Requirement: A registration form can carry a fee to pay on submit (REQ-OBRF-010)

A registration form SHALL accept an optional `fee` with `amount` (greater than zero), `currency` (default `EUR`) and `description`. A form with a fee SHALL NOT be saved unless its sign-in level is `substantial` or higher. The served form SHALL carry the fee beside `minTrust`, so the portal can ask for payment after submit.

**ID:** REQ-OBRF-010

#### Scenario: A permit with a fee
- **GIVEN** a registration form "Evenementenvergunning" with sign-in level `substantial`
- **WHEN** the maker sets a fee of 25 EUR with the line "Leges evenementenvergunning" and saves
- **THEN** the form is saved with the fee
- **AND** the served form carries `fee: { amount: 25, currency: "EUR", description: "Leges evenementenvergunning" }`

#### Scenario: A fee on an anonymous form
- **GIVEN** a registration form without a sign-in level
- **WHEN** the maker sets a fee and saves
- **THEN** the save is refused with the reason that a form with a fee needs sign-in at level substantial
