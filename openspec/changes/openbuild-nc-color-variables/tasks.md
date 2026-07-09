## 1. Badge cluster — ApplicationDetailHeader.vue

- [ ] 1.1 Replace `.ob-detail-header__badge--status` (`src/components/applicationDetail/ApplicationDetailHeader.vue:555-558`): background `rgba(67,118,252,.15)` → `var(--color-primary-element-light)`, color `#2e5ed9` → `var(--color-primary-element)`.
- [ ] 1.2 Replace `.ob-detail-header__badge--role` (`:560-563`): background `rgba(120,120,120,.15)` → `var(--color-background-dark)`, color `#555` → `var(--color-text-maxcontrast)`.
- [ ] 1.3 Replace `.ob-detail-header__badge--semver` (`:565-568`): background `rgba(46,184,102,.15)` → `var(--color-success-hover)`, color `#246b3d` → `var(--color-success-text)`.
- [ ] 1.4 Replace `.ob-detail-header__badge--type-hybrid` (`:575-578`): background `rgba(120,120,120,.18)` → `var(--color-background-dark)`, color `#444` → `var(--color-text-maxcontrast)`.

## 2. Role chips — GroupsWidget.vue

- [ ] 2.1 Replace `.ob-groups-widget__row-role--owners` (`src/components/applicationDetail/widgets/GroupsWidget.vue:175-178`): background → `var(--color-warning-hover)`, color `#a06900` → `var(--color-warning-text)`.
- [ ] 2.2 Replace `.ob-groups-widget__row-role--editors` (`:180-183`): background → `var(--color-primary-element-light)`, color `#2e5ed9` → `var(--color-primary-element)`.
- [ ] 2.3 Replace `.ob-groups-widget__row-role--viewers` (`:185-188`): background → `var(--color-background-dark)`, color `#555` → `var(--color-text-maxcontrast)`.

## 3. Text-on-brand fixes

- [ ] 3.1 `src/views/VersionHistory.vue:479` — `.version-history__badge--published` color `#fff` → `var(--color-success-text, #fff)` (matches the production badge's existing pattern at `:484`).
- [ ] 3.2 `src/dialogs/IconUploadSection.vue:428` — `.ob-icon-section__file-label` color `#fff` → `var(--color-primary-element-text, #fff)`.

## 4. Document the intentional exemptions

- [ ] 4.1 Add `/* intentional: simulated light/dark canvas for icon preview — must NOT track the theme */` above `src/dialogs/IconUploadSection.vue:398-405` (`--light` `#ffffff`, `--dark` `#1c1c1e`).
- [ ] 4.2 Add the same comment above `src/dialogs/CreateApplicationWizard/Step4Review.vue:227-229` (`#1a1a2e`) and its `:242-244` caption (`#aaa`).

## 5. Verification

- [ ] 5.1 `grep -rnE 'color:\s*#|background:\s*#|rgba\(' src/` — remaining hits are only the commented exemptions and `var(..., #fallback)` fallbacks.
- [ ] 5.2 Rebuild and visually verify the application-detail header badges and group chips in BOTH light and dark themes (dark-mode text must be readable — the driving bug).
- [ ] 5.3 Run `npm run lint` / stylelint — clean.
