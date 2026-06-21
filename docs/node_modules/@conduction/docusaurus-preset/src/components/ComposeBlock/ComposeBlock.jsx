/**
 * <ComposeBlock />
 *
 * Code block styled like a terminal-adjacent compose-yaml panel.
 * Mirrors the .compose treatment from preview/pages/demo.html: dark
 * cobalt-900 background, IBM Plex Mono, an optional file-name pill
 * at the top, and a copy-to-clipboard button on hover.
 *
 * Used wherever a page needs to drop in a chunk of YAML, JSON, bash,
 * or any other plain text the reader might want to copy: /demo
 * (docker-compose), /apps/<slug> (sample register schema), /docs
 * configuration recipes.
 *
 * Usage in MDX:
 *
 *   <ComposeBlock filename="docker-compose.yml" lang="yaml">
 *     {`services:
 *       nextcloud:
 *         image: nextcloud:latest
 *         ports: ["8080:80"]
 *     `}
 *   </ComposeBlock>
 *
 *   <ComposeBlock>
 *     {`docker compose up -d
 *     docker compose logs -f nextcloud`}
 *   </ComposeBlock>
 *
 * Note: this component is for verbatim copy-paste blocks. For
 * syntax-highlighted code, use Docusaurus's built-in fenced code
 * blocks (```yaml ...) which Docusaurus colorises via Prism.
 */

import React, {useState, useCallback, useRef} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';
import styles from './ComposeBlock.module.css';

export default function ComposeBlock({filename, lang, children, className}) {
  const isBrowser = useIsBrowser();
  const [copied, setCopied] = useState(false);
  const preRef = useRef(null);

  const onCopy = useCallback(() => {
    if (!isBrowser) return;
    const text = preRef.current?.textContent || '';
    if (!text || !navigator.clipboard) return;
    navigator.clipboard.writeText(text).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    }).catch(() => {/* clipboard API can fail in iframes; just no-op */});
  }, [isBrowser]);

  return (
    <div className={[styles.block, className].filter(Boolean).join(' ')} role="region" aria-label={filename || 'Code block'}>
      {filename && <span className={styles.filename}>{filename}</span>}
      <button
        type="button"
        className={styles.copy}
        onClick={onCopy}
        aria-label={copied ? 'Copied to clipboard' : 'Copy to clipboard'}
        title={copied ? 'Copied' : 'Copy'}
      >
        {copied ? '✓ copied' : 'copy'}
      </button>
      <pre ref={preRef} className={styles.pre}>
        <code className={lang ? `language-${lang}` : undefined}>{children}</code>
      </pre>
    </div>
  );
}
