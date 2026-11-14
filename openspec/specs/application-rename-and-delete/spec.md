# application-rename-and-delete Specification

## Purpose

An owner changes an app's name, description and slug after creating it, and
deletes an app they no longer need, choosing whether its data goes with it.
This spec was written on 2026-10-07, after the fact, from the code on
`development`. It describes what the code does today and covers the parity
rows `app-rename-describe` and `app-delete`.

Files: `lib/Settings/openbuild_register.json` (Application schema,
`slug`/`name`/`description`), `src/components/ApplicationDetailActions.vue`
(the Edit action), `lib/Listener/HybridMetadataLockListener.php` (identity
lock for hybrid apps), `src/components/AppDeleteDialogSlot.vue` and
`src/dialogs/DeleteAppDialog.vue` (the delete dialog),
`lib/Controller/ApplicationPublishController.php` (`destroy`) and
`lib/Service/ApplicationDeletionService.php` (`deleteApplication`).

## Requirements

### Requirement: An owner edits an app's name, description and slug

The application detail page SHALL offer an Edit action that opens the shared
object edit form on the Application record. `src/manifest.json` sets
`showEditAction: false` on that page, and `ApplicationDetailActions.vue`
SHALL render Edit itself (`id: app-edit-record`) as the last inline action,
calling the `openEditForm` function the detail page hands down through its
`#actions` slot scope. The form SHALL edit the Application schema's `name`,
`description` and `slug`. A new slug MUST match the schema pattern
`^(?!.*-dark$)[a-z0-9][a-z0-9-]*[a-z0-9]$` with 2 to 48 characters, so a
`-dark` suffix is refused.

@e2e exclude retrofit spec written after the fact; the Edit action is covered by Vitest on ApplicationDetailActions and the form is nextcloud-vue's shared edit form

#### Scenario: Rename a virtual app

- **GIVEN** a virtual app the user owns
- **WHEN** the user clicks Edit on the app's detail page, changes the name and description and saves
- **THEN** the Application record SHALL hold the new name and description
- **AND** the detail page SHALL show them

#### Scenario: A slug ending in -dark is refused

- **WHEN** the user saves a slug ending in `-dark`
- **THEN** the save SHALL be refused by the schema pattern, because that slug would collide with the dark icon route `/icons/{slug}-dark.svg`

### Requirement: A hybrid app's identity stays read-only

For an app with `appType: hybrid`, the `slug` and `name` SHALL NOT change,
because they mirror the installed Nextcloud app the hybrid app customises.
`HybridMetadataLockListener` MUST reject an update that changes either field
on a hybrid app, and the schema's `x-openregister-readonly-when` on `slug`,
`name` and `description` SHALL show those fields read-only in the form. A
virtual app (or one without `appType`) SHALL keep full edit of slug and name.

@e2e exclude retrofit spec written after the fact; the lock is covered by PHPUnit on HybridMetadataLockListener

#### Scenario: Renaming a hybrid app is rejected

- **GIVEN** a hybrid app that customises the installed app `pipelinq`
- **WHEN** an update changes its `slug` or `name`
- **THEN** the listener SHALL reject the update and the stored values SHALL stay as they were

### Requirement: An owner deletes an app and chooses whether its data goes too

The applications index SHALL open buildiq's own `DeleteAppDialog` from the
table's Delete row action, through the `delete-dialog` slot that
`src/manifest.json` fills with `AppDeleteDialogSlot`. The dialog SHALL ask
"Delete "{name}" and all of its versions? This cannot be undone." and offer
one checkbox, "Also permanently delete all data (the app's registers and
everything stored in them)", unticked by default. On confirm the slot SHALL
call `DELETE /apps/buildiq/api/applications/{appUuid}` with
`deleteData: 1` or `0`.

The `destroy` endpoint SHALL allow only an owner of the app, or an admin,
and SHALL answer 403 otherwise. It MUST treat only an explicit affirmative
`deleteData` as a purge (`wantsDataPurge`), so a missing or unclear value
keeps the data. `ApplicationDeletionService::deleteApplication` SHALL delete
the app's versions, its `built-app-route` entries and the Application
record. When `deleteData` is set it SHALL also delete each version's
register, the objects in it, and the schemas those registers owned that no
other register still uses. The response SHALL list in `orphanedResources`
only the resources it tried and failed to remove.

@e2e exclude retrofit spec written after the fact; covered by Vitest on AppDeleteDialogSlot and DeleteAppDialog, PHPUnit on ApplicationPublishController and ApplicationDeletionService, and the Newman contract collection

#### Scenario: Delete an app and keep its data

- **GIVEN** an app the user owns, with two versions and a register holding records
- **WHEN** the user deletes it from the applications index without ticking the data checkbox
- **THEN** the versions, routes and Application record SHALL be deleted
- **AND** the registers and their records SHALL stay in OpenRegister
- **AND** `orphanedResources` SHALL be empty

#### Scenario: Delete an app with its data

- **WHEN** the owner ticks "Also permanently delete all data" and confirms
- **THEN** the version registers, their records and the schemas no other register uses SHALL be deleted as well
- **AND** the app's slug SHALL be free to use again

#### Scenario: A non-owner cannot delete

- **WHEN** a user who is neither an owner of the app nor an admin calls the delete endpoint
- **THEN** the endpoint SHALL answer 403 and delete nothing
