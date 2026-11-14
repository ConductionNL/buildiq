## ADDED Requirements

### Requirement: A form can require sign-in at a trust level (REQ-BQCV-019)

A maker SHALL be able to set a sign-in level of low, substantial or high on a registration form and on an externally provisioned form page, or leave it empty for an anonymous form. A form with a level SHALL only be filled in and submitted by someone signed in at that level or higher, for example through DigiD for citizens or eHerkenning for businesses, and SHALL grant no anonymous create. An unknown level, or a level on a form that is also marked public, SHALL be refused on save.

#### Scenario: A form that needs a substantial sign-in

- **GIVEN** a registration form with sign-in level substantial served on a portal
- **WHEN** a visitor who is not signed in opens the form
- **THEN** the portal asks them to sign in at substantial level before the form shows
- **AND** no anonymous create is granted on the form's schema

#### Scenario: A public form cannot carry a level

- **GIVEN** a registration form marked public
- **WHEN** the maker sets the sign-in level to high and saves
- **THEN** the save is refused with a message that a public form cannot require sign-in

### Requirement: A form's use is reported where it is served (REQ-BQCV-020)

For each registration form served on a portal, the portal's traffic view SHALL show how many people started the form, how many submitted it, how many abandoned it, the completion rate, and the field most people left on. The counts SHALL be numbers only and SHALL hold no answers.

#### Scenario: An owner sees where people give up

- **GIVEN** a registration form started 200 times and submitted 150 times on a portal, where most of the 50 who left stopped at the field `iban`
- **WHEN** the form's owner opens the portal's forms traffic view
- **THEN** the form's row shows 200 started, 150 submitted, 50 abandoned and a 75 percent completion rate
- **AND** the row names `iban` as the field most people left on
