/**
 * <IntegrationIcon />
 *
 * Render a token-painted glyph for one of the integrations in the
 * Conduction registry: Nextcloud-bundled apps (Mail, Calendar, Files,
 * Talk, Decks, RSS, Activity), workflow tools (xWiki, n8n, Windmill,
 * OpenProject, Keycloak), LLM providers (Claude, Mistral, Ollama,
 * OpenAI, Gemini), and adjacent Conduction extensions (OpenTalk,
 * Matrix, Mattermost, OpenExchange).
 *
 * The SVG content lives in registry.js as inline strings, mirroring
 * the .svg files under brand/assets/integrations/. Both copies use
 * `fill="currentColor"` (or stroke) so the icon tints to whatever
 * `color` the parent CSS sets. That means a SidebarMock tab can
 * render a coloured Mail icon when active and a muted Mail icon when
 * inactive without swapping the SVG.
 *
 * Usage:
 *
 *   <IntegrationIcon name="claude" />               // 16×16 default
 *   <IntegrationIcon name="xwiki" size="md" />      // 24×24
 *   <IntegrationIcon name="n8n" size="lg" />        // 40×40
 *
 * Props:
 *   - name:  one of INTEGRATIONS keys           (required)
 *   - size:  'xs' | 'sm' (default) | 'md' | 'lg' | 'xl'
 *   - title: aria-label override; defaults to registry label
 *   - className: string
 */

import React from 'react';
import styles from './IntegrationIcon.module.css';
import amStyles from '../AppMock/AppMock.module.css';
import { INTEGRATIONS } from './registry.js';

export default function IntegrationIcon({ name, size = 'sm', title, className }) {
  const entry = INTEGRATIONS[name];
  if (!entry) {
    return (
      <span
        className={[amStyles.am, styles.ii, styles[size], className].filter(Boolean).join(' ')}
        title={`Unknown integration: ${name}`}
        aria-label={`Unknown integration: ${name}`}
      />
    );
  }
  return (
    <span
      className={[amStyles.am, styles.ii, styles[size], className].filter(Boolean).join(' ')}
      role="img"
      aria-label={title || entry.label}
      dangerouslySetInnerHTML={{ __html: entry.svg }}
    />
  );
}
