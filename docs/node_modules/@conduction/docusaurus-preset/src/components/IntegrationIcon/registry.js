/**
 * Integration icon registry.
 *
 * Each entry mirrors the SVG content of a file under
 * brand/assets/integrations/{category}/{name}.svg. Runtime consumers
 * (the React <IntegrationIcon /> component, build-kit kit pages,
 * future SidebarMock tab labels) read inline strings from here so the
 * icon can render with `currentColor` fill and tint to whatever
 * colour the parent CSS sets. The .svg files in brand/ are the
 * designer-readable copy; this file is the consumer copy. They
 * stay manually in sync (each is one file, edits land in both).
 *
 * Categories:
 *   nextcloud-bundled — surfaces the Nextcloud workspace already
 *     ships (Mail, Calendar, Files, Talk, Decks, RSS, Activity).
 *   workflow          — third-party workflow / wiki / SSO tools that
 *     Conduction integrates with (xWiki, n8n, Windmill, OpenProject,
 *     Keycloak).
 *   llm               — large-language-model providers we consume via
 *     OpenConnector / DocuDesk (Claude, Mistral, Ollama, OpenAI,
 *     Gemini).
 *   conduction-ext    — external apps in the Conduction Nextcloud
 *     ecosystem (OpenTalk, Matrix, Mattermost, OpenExchange).
 *
 * The icons themselves are simplified, monochromatic, representational
 * marks in the Conduction visual style. They are NOT replacements for
 * official brand marks; consumers that need the canonical logo of a
 * third-party tool should use that tool's own asset.
 */

export const INTEGRATIONS = {

  // ---- Nextcloud-bundled ----
  'mail': {
    category: 'nextcloud-bundled',
    label: 'Mail',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="1.5"/><path d="M3.5 7.5l8.5 6 8.5-6"/></svg>',
  },
  'calendar': {
    category: 'nextcloud-bundled',
    label: 'Calendar',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="1.5"/><path d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15" r="1.4" fill="currentColor" stroke="none"/></svg>',
  },
  'files': {
    category: 'nextcloud-bundled',
    label: 'Files',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
  },
  'talk': {
    category: 'nextcloud-bundled',
    label: 'Talk',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 16V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H8l-4 3z"/></svg>',
  },
  'decks': {
    category: 'nextcloud-bundled',
    label: 'Decks',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="4" width="5" height="16" rx="1"/><rect x="9.5" y="4" width="5" height="11" rx="1" opacity="0.7"/><rect x="16" y="4" width="5" height="7" rx="1" opacity="0.4"/></svg>',
  },
  'rss': {
    category: 'nextcloud-bundled',
    label: 'RSS',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5a14 14 0 0 1 14 14"/><path d="M5 11a8 8 0 0 1 8 8"/><circle cx="6" cy="18" r="2" fill="currentColor"/></svg>',
  },
  'activity': {
    category: 'nextcloud-bundled',
    label: 'Activity',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2.5-7 5 14L17 12h4"/></svg>',
  },

  // ---- Workflow / auth ----
  'xwiki': {
    category: 'workflow',
    label: 'xWiki',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 4h3.4L9 9.5 11.6 4H15l-4.4 8.5L15 21h-3.4L9 15.5 6.4 21H3l4.4-8.5z"/><rect x="16.5" y="4" width="1.6" height="17" opacity="0.55"/><rect x="19.4" y="4" width="1.6" height="17" opacity="0.35"/></svg>',
  },
  'n8n': {
    category: 'workflow',
    label: 'n8n',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M9 12h6M9 12l9-6M9 12l9 6" stroke="currentColor" stroke-width="2" fill="none"/></svg>',
  },
  'windmill': {
    category: 'workflow',
    label: 'Windmill',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="2"/><path d="M12 12L17 5L19 8L14 12zM12 12L19 17L16 19L12 14zM12 12L5 7L8 5L12 10z"/><path d="M11 14h2v8h-2z"/></svg>',
  },
  'openproject': {
    category: 'workflow',
    label: 'OpenProject',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.35.37h-1.86a4.628 4.628 0 0 0-4.652 4.624v5.609H4.652A4.628 4.628 0 0 0 0 15.23v3.721c0 2.569 2.083 4.679 4.652 4.679h1.86c2.57 0 4.652-2.11 4.652-4.679v-3.72h-2.791v3.88c0 1.026-.835 1.886-1.861 1.886h-1.86c-1.027 0-1.861-.864-1.861-1.886V15.23a1.839 1.839 0 0 1 1.86-1.833h14.697c2.57 0 4.652-2.11 4.652-4.679V4.997A4.628 4.628 0 0 0 19.35.37z"/></svg>',
  },
  'keycloak': {
    category: 'workflow',
    label: 'Keycloak',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="12" r="4"/><circle cx="9" cy="12" r="1.4" fill="#fff"/><path d="M13 12h8M17 12v3M21 12v3" stroke="currentColor" stroke-width="2" fill="none"/></svg>',
  },

  // ---- LLMs ----
  'claude': {
    category: 'llm',
    label: 'Claude',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="3.5"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M5.6 5.6l2.8 2.8"/><path d="M15.6 15.6l2.8 2.8"/><path d="M18.4 5.6l-2.8 2.8"/><path d="M8.4 15.6l-2.8 2.8"/></g></svg>',
  },
  'mistral': {
    category: 'llm',
    label: 'Mistral',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="2" y="3" width="20" height="3" rx="0.5"/><rect x="2" y="8" width="14" height="3" rx="0.5" opacity="0.75"/><rect x="2" y="13" width="20" height="3" rx="0.5" opacity="0.6"/><rect x="2" y="18" width="14" height="3" rx="0.5" opacity="0.4"/></svg>',
  },
  'ollama': {
    category: 'llm',
    label: 'Ollama',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><ellipse cx="9" cy="14" rx="5" ry="6"/><ellipse cx="14.5" cy="6" rx="2" ry="3"/><path d="M14 9l1.5 2.4 2.5-1z"/><circle cx="6.5" cy="13" r="0.9" fill="#fff"/></svg>',
  },
  'openai': {
    category: 'llm',
    label: 'OpenAI',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><polygon points="12,3 21,8 21,16 12,21 3,16 3,8"/><polygon points="12,8 17,11 17,14 12,17 7,14 7,11" fill="currentColor" stroke="none"/></svg>',
  },
  'gemini': {
    category: 'llm',
    label: 'Gemini',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L8 8h8z"/><path d="M8 8L4 14l8 8 8-8-4-6z" opacity="0.7"/><circle cx="18.5" cy="4.5" r="1" opacity="0.6"/><circle cx="20" cy="7" r="0.6" opacity="0.4"/></svg>',
  },

  // ---- Conduction Nextcloud-extension apps ----
  'opentalk': {
    category: 'conduction-ext',
    label: 'OpenTalk',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="12.5" r="7"/><circle cx="18.5" cy="6.5" r="2.5" fill="currentColor" stroke="none"/></svg>',
  },
  'matrix': {
    category: 'conduction-ext',
    label: 'Matrix',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true"><path d="M.632.55v22.9H2.28V24H0V0h2.28v.55zm7.043 7.26v1.157h.033c.309-.443.683-.784 1.117-1.024.433-.245.936-.365 1.5-.365.54 0 1.033.107 1.481.314.448.208.785.582 1.02 1.108.254-.374.6-.706 1.034-.992.434-.287.95-.43 1.546-.43.453 0 .872.056 1.26.167.388.11.716.286.993.53.276.245.489.559.646.951.152.392.23.863.23 1.417v5.728h-2.349V11.52c0-.286-.01-.559-.032-.812a1.755 1.755 0 0 0-.18-.66 1.106 1.106 0 0 0-.438-.448c-.194-.11-.457-.166-.785-.166-.332 0-.6.064-.803.189a1.38 1.38 0 0 0-.48.499 1.946 1.946 0 0 0-.231.696 5.56 5.56 0 0 0-.06.785v4.768h-2.35v-4.8c0-.254-.004-.503-.018-.752a2.074 2.074 0 0 0-.143-.688 1.052 1.052 0 0 0-.415-.503c-.194-.125-.476-.19-.854-.19-.111 0-.259.024-.439.074-.18.051-.36.143-.53.282-.171.138-.319.337-.439.595-.12.259-.18.6-.18 1.02v4.966H5.46V7.81zm15.693 15.64V.55H21.72V0H24v24h-2.28v-.55z"/></svg>',
  },
  'mattermost': {
    category: 'conduction-ext',
    label: 'Mattermost',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true"><path d="M12.081 0C7.048-.034 2.339 3.125.637 8.153c-2.125 6.276 1.24 13.086 7.516 15.21 6.276 2.125 13.086-1.24 15.21-7.516 1.727-5.1-.172-10.552-4.311-13.557l.126 2.547c2.065 2.282 2.88 5.512 1.852 8.549-1.534 4.532-6.594 6.915-11.3 5.321-4.708-1.593-7.28-6.559-5.745-11.092 1.031-3.046 3.655-5.121 6.694-5.67l1.642-1.94A4.87 4.87 0 0 0 12.08 0z"/></svg>',
  },
  'openexchange': {
    category: 'conduction-ext',
    label: 'OpenExchange',
    svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="8.5" cy="12" r="5"/><path d="M14.5 7l6 10M14.5 17l6-10"/></svg>',
  },
};

export const INTEGRATION_CATEGORIES = {
  'nextcloud-bundled': { label: 'Nextcloud bundled', desc: 'Surfaces every Nextcloud workspace already ships.' },
  'workflow':          { label: 'Workflow & auth',   desc: 'Third-party tools we wire into Conduction apps via OpenConnector.' },
  'llm':               { label: 'LLM providers',     desc: 'Models we call from OpenConnector and DocuDesk.' },
  'conduction-ext':    { label: 'Conduction extensions', desc: 'Adjacent apps in the Conduction Nextcloud ecosystem.' },
};
