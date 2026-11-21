## ADDED Requirements

### Requirement: The document-template preview MUST be sanitized before render

The document-template preview SHALL be sanitized before it is rendered: the
`previewContent` bound via `v-html` in `DocumentTemplateAttachmentDialog.vue`
MUST pass through `DOMPurify.sanitize(...)` (full HTML profile) — or be rendered
in a sandboxed iframe — before it reaches the binding, and the app MUST NOT rely
solely on the page CSP for this sink. The preview is the Docudesk endpoint's
response (`data.html || data.content || data.preview`); because a document
template can be authored by one user and previewed in another user's
authenticated session, it is a cross-user (stored) XSS sink.

#### Scenario: Injected script in a preview is neutralized
- **WHEN** a document-template preview contains `<script>` or an inline event
  handler (e.g. `onerror=`)
- **THEN** the rendered output contains no executable script — the markup is
  stripped/neutralized by sanitization before render

#### Scenario: Benign preview markup renders unchanged
- **WHEN** a preview contains ordinary formatting markup (headings, lists,
  emphasis)
- **THEN** the sanitized output preserves that formatting