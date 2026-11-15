## ADDED Requirements

### Requirement: A rule set can be exported as a file (REQ-BQCV-017)

The rule sets page SHALL offer an Export action on each rule set. Export SHALL download one JSON file that holds the rule set's metadata together with its decision tables, condition-action rules and test cases, so the file can be imported elsewhere. Execution logs SHALL NOT be part of the file.

#### Scenario: A maker exports a rule set

- **GIVEN** a rule set with one decision table and two test cases
- **WHEN** a maker chooses Export on that rule set
- **THEN** the browser downloads a JSON file named after the rule set's slug
- **AND** the file holds the decision table and both test cases, and no execution logs
