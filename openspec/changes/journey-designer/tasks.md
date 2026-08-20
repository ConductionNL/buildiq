# Tasks: journey-designer

> Journey authoring beside the Page Designer (ADR-032 `kind: code`).
> Checkbox budget: 4 tasks × 2 = 8 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Step sequence editor over existing forms
- **spec_ref**: `openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects`
- **files**: `src/views/JourneyDesigner.vue`, `src/components/journey-editor/JourneyStepsManager.vue`, `src/components/journey-editor/__tests__/JourneyStepsManager.spec.js`
- **acceptance_criteria**:
  - Authors `form` / `review` / `confirmation` steps; a `form` step stores a REFERENCE, never a copied field definition — asserted on the saved object
  - Reordering rewrites order with step contents unchanged
  - A reference to a deleted form is surfaced, not silently dropped — a journey quietly missing a step still runs and collects less than it should
  - Field authoring stays in the existing form editor; no field builder is duplicated here
- [ ] Implement
- [ ] Test

### Task 2: Branch editor bound to the canonical operator set
- **spec_ref**: `openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set`
- **files**: `src/components/journey-editor/JourneyBranchEditor.vue`, `src/components/journey-editor/__tests__/JourneyBranchEditor.spec.js`
- **acceptance_criteria**:
  - The operator list is DERIVED from the canonical schema, so it cannot drift — asserted by comparing the offered list against the schema enum, not against a hardcoded expectation
  - A rule referencing a field first asked at a later step is refused, naming the field and the ordering
  - Rules are stored as `visibleWhen` predicates with no designer-local extension
- [ ] Implement
- [ ] Test

### Task 3: Author-time validation of writes[]
- **spec_ref**: `openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time`
- **files**: `src/components/journey-editor/JourneyWritesEditor.vue`, `src/services/journeyValidation.js`, `src/services/__tests__/journeyValidation.spec.js`
- **acceptance_criteria**:
  - Target register/schema existence and every mapped property are validated before save; an unknown property is refused by name
  - Author-time and run-time validation call the SAME validator, asserted by feeding one invalid mapping through both and comparing the rejections — a designer that validates differently is false confidence, not a safety net
  - A dependent write referencing a preceding write's id validates
- [ ] Implement
- [ ] Test

### Task 4: Authorisation and live preview
- **spec_ref**: `openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised`
- **files**: `lib/Controller/JourneyDesignerController.php`, `src/components/journey-editor/JourneyPreviewPane.vue`, `tests/Unit/Controller/JourneyDesignerControllerTest.php`
- **acceptance_criteria**:
  - Journey authoring requires its own permission; a Page-Designer-only user is refused, and the refusal names journey authoring specifically
  - The refusal is tested for real — a permission check that is never observed refusing is indistinguishable from no check
  - The preview mounts the REAL `CnJourney`; step sequence, fields, validation and branching match the portal render
  - Advancing a preview through a `writes[]` step creates NO object in any target register
- [ ] Implement
- [ ] Test
