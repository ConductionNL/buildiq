# Retrofit — template-catalogue-ui

Describes observed behaviour of the starter-template gallery UI — the
`TemplateGallery` view and the `CloneTemplateDialog` modal — as 2 new REQs.

Code already exists (it implements the `openbuild-template-catalogue` backend
capability). This change retroactively specifies the gallery filtering, clone
submission, and post-clone redirect behaviour so gate-16 spec-coverage can trace
each method.

## Approach
- Describe observed template fetch/filter, category options, screenshot
  resolution, and clone submit/redirect.
- REQs match behaviour, not aspiration. No behaviour is changed.
