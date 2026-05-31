# Retrofit — application-detail-ui

Describes observed behaviour of the Application detail UI layer — the
`ApplicationDetailHeader` cockpit, its overview widgets
(`applicationDetail/widgets/*`), the `ApplicationCard` grid tile, the
detail action bar (`ApplicationDetailActions`), the manifest-diff viewer
(`ManifestDiff`), the per-application tabs (`tabs/*`), the
`VirtualAppsActions` toolbar, and the `App.vue` shell — as 5 new REQs.

Code already exists (it implements the `application-detail-overview` backend
capability). This change retroactively specifies the frontend behaviour at the
component-method level so gate-16 spec-coverage can trace each method.

## Approach
- Describe observed computed surfaces, fetches, emit/open contracts, and
  version selection per component.
- REQs match behaviour, not aspiration. No behaviour is changed.
