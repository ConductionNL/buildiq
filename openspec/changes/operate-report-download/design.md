# Design: operate-report-download

## Board

**BqRapportages** on canvas part 2 (`QAAxpcsFKBCvDUGbQbwtCa`) draws one page
"Rapportages" with the subtitle "Alle apps van deze installatie, bijgewerkt om
08.10" and two buttons on the right of the title: "Downloaden" (secondary) and
"Acties". Under it the tabs "Apps" and "Versies en exports", then the counts,
the donuts and the table "Meest recent", in the order main spec
`buildiq-reports` describes.

Live, the two tabs are two report pages behind the Reports card page. This
change keeps that split (the card page is the shared `CnReportsPage`
pattern every app uses) and puts "Download" where the board draws it: in the
header of each report, left of the actions.

## D1. The download belongs to the dashboard renderer

Both reports are `type: dashboard` pages with no buildiq component (ADR-112,
ADR-114). The figures exist only inside `CnDashboardPage`'s widgets once they
have resolved their aggregations. So the serialiser lives in nextcloud-vue:
`CnDashboardPage` gets an opt-in `config.download: true` that adds a header
button and builds the CSV from what its widgets already loaded. No second
round of requests, and any app's dashboard can switch it on.

buildiq's part is to set `"download": true` on `ApplicationsReport` and
`DeliveryReport` and to give each a `downloadName`.

## D2. CSV shape

```
widget,label,value
Published,Published,5
By status,published,5
By status,draft,2
Most recent,Pipelinq | hybride | Gepubliceerd,
```

UTF-8 with a BOM so Excel opens Dutch labels correctly, comma separator,
values quoted when they contain a comma. A widget that failed to load writes
one row with value `error`.

## D3. Rights

The download is what the page shows, so it carries the page's rights. No
endpoint is added.

## Risks

- A widget still loading when the button is clicked: the button is disabled
  until every widget has settled.
