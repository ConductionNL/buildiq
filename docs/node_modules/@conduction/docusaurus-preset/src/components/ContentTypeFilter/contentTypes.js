/**
 * Single source of truth for the academy content-type taxonomy.
 *
 * Used by:
 *   - <ContentTypeFilter /> for the chip row
 *   - <ContentCard />        to render the type chip and pick a bullet colour
 *   - <ContentDetailHero />  to render the type chip in the header
 *   - schemas/academy/content.schema.json (mirrored in the JSON Schema)
 *
 * Content types correspond 1:1 with frontmatter `contentType:` values
 * in academy MDX files. Extending this list here is enough to surface
 * a new chip across every site that consumes the preset; the JSON
 * Schema in /schemas/academy/content.schema.json must be updated in
 * lockstep.
 */

export const CONTENT_TYPES = [
  'blog',
  'guide',
  'case-study',
  'webinar',
  'tutorial',
];

export const CONTENT_TYPE_LABELS = {
  'blog':       'Blog',
  'guide':      'Guide',
  'case-study': 'Case study',
  'webinar':    'Webinar',
  'tutorial':   'Tutorial',
};

/**
 * Plural, sentence-case labels for the chip row. We use plural for
 * the filter ("Blogs", "Case studies") because each chip filters a
 * collection.
 */
export const CONTENT_TYPE_PLURAL_LABELS = {
  'blog':       'Blogs',
  'guide':      'Guides',
  'case-study': 'Case studies',
  'webinar':    'Webinars',
  'tutorial':   'Tutorials',
};

/**
 * Bullet (HexBullet) colour for the type chip. One token per type so
 * a card's content-type pill is identifiable at a glance. Only one
 * type uses KNVB orange (webinar), so the kit's "one orange accent
 * per screen" rule still holds at landing scale.
 */
export const CONTENT_TYPE_BULLET_COLOR = {
  'blog':       'var(--c-blue-cobalt)',
  'guide':      'var(--c-mint-500)',
  'case-study': 'var(--c-cobalt-700)',
  'webinar':    'var(--c-orange-knvb)',
  'tutorial':   'var(--c-cobalt-400)',
};
