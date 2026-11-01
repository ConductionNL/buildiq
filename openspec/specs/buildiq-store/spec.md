# buildiq-store Specification

## Purpose
How buildiq answers OpenRegister's store routes. Created by change `store-publish-passthrough` (archived 2026-10-30); holds the requirement that the publish route answers instead of failing.

## Requirements

### Requirement: The publish route answers

`POST /api/store/publish` MUST answer: 401 to an anonymous caller, 403 to a user `canPublish()` does not admit, 400 without an `item`, and otherwise the outcome OpenRegister's store service gives for buildiq's store.

#### Scenario: a publish to buildiq's store

@e2e exclude Asserted in tests/Unit/Controller/StoreControllerTest.php; buildiq declares no publish fields, so the outcome is not_publishable.

- GIVEN a signed-in user
- WHEN they post an item to /api/store/publish
- THEN the answer is 200 with outcome not_publishable, never a server error
