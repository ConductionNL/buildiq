/**
 * @conduction/docusaurus-preset/data/audience
 *
 * Audience taxonomy for academy content. Mirrors the three tiers
 * defined in design-system/CLAUDE.md and preview/identity/audience.html:
 *
 *   1. MKB-beslisser / IT-lead  (primary)
 *   2. Overheid-beslisser       (secondary)
 *   3. Developer / integrator   (tertiary)
 *
 * Used by:
 *   - <ContentCard/>            renders the audience pill row
 *   - <ModuleCard/>              same
 *   - <FeaturedCard/>            same
 *   - <ContentTypeFilter/>       when reused as the audience filter row
 *   - schemas/academy/content.schema.json (mirrored as the audience enum)
 */

export const AUDIENCES = ['mkb', 'government', 'developer'];

export const AUDIENCE_LABELS = {
  mkb:        'Voor MKB',
  government: 'Voor overheid',
  developer:  'Voor developers',
};

/**
 * Plural, sentence-case labels for the chip row. Identical to the
 * singular labels because each one already reads as a collection target
 * ("Voor MKB", "Voor developers"), unlike content types ("Blog" vs
 * "Blogs"). Kept as a separate export for symmetry with
 * CONTENT_TYPE_PLURAL_LABELS so call sites that switch between the two
 * filter rows don't need a branch.
 */
export const AUDIENCE_PLURAL_LABELS = AUDIENCE_LABELS;

/**
 * Bullet (HexBullet) colour for the audience chip. We do NOT brand the
 * audience pills with the orange accent — the "one orange per
 * component" rule reserves orange for the page's single highlighted
 * action. Audience uses three cobalt shades to give each tier a quiet
 * differentiator without competing with the type chip.
 */
export const AUDIENCE_BULLET_COLOR = {
  mkb:        'var(--c-blue-cobalt)',
  government: 'var(--c-cobalt-700)',
  developer:  'var(--c-cobalt-400)',
};

/**
 * Short labels used when the "For: A, B, C" line composes the
 * audience set into a single sentence (the layout that replaced the
 * audience pill row on ContentCard / ModuleCard / FeaturedCard).
 * Dropping the "Voor" prefix avoids duplicating the leading "For:".
 */
export const AUDIENCE_SHORT_LABELS = {
  mkb:        'MKB',
  government: 'Overheid',
  developer:  'Developers',
};
