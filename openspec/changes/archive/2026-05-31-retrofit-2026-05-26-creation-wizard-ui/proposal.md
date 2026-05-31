# Retrofit — creation-wizard-ui

Describes observed behaviour of the Create Application Wizard UI — the
`CreateApplicationWizard` host and its four steps (`Step1Basics`,
`Step2Preset`, `Step3Custom`, `Step4Review`) plus the shared
`IconUploadSection` — as 4 new REQs.

Code already exists (it implements the `application-creation-wizard` backend
capability). This change retroactively specifies the wizard's per-step
validation, navigation, payload merge, and icon-upload behaviour so gate-16
spec-coverage can trace each method.

## Approach
- Describe observed step validation, navigation gating, payload merge, and
  submit per component.
- REQs match behaviour, not aspiration. No behaviour is changed.
