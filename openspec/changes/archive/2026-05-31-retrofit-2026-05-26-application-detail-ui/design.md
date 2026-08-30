# Design — application-detail-ui (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The Application detail UI already ships; this records its observed
behaviour as numbered REQs so gate-16 spec-coverage can trace each method to a
requirement. No behaviour is changed.

`ApplicationDetailHeader` is registered as the `headerComponent` for the
`/applications/:objectId` detail route and acts as the maintainer cockpit:
version pills, KPI grid, activity sparkline, and the stacked overview widgets
(register / schemas / groups / pages / menu). `ApplicationCard` is the grid
tile on the index. `App.vue` is the app shell. The tabs and action bar surface
manifest, versions, icon, permissions, and publish actions.
