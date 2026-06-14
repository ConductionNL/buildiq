/**
 * <cn-domain-tree> — vertical apex-and-branches layout for domain maps.
 *
 * Wraps an apex hex and a row of branch hexes with the kit's standard
 * connector lines. Designed for the identity / Websites page, but
 * useful anywhere a one-to-many "trunk → leaves" relationship needs
 * to render. Multi-trunk diagrams stack two or more <cn-domain-tree>
 * elements vertically.
 *
 * Slots
 *   apex     — the trunk hex; required (typically <cn-hex size="xl">)
 *   default  — branch hexes; rendered in a centered horizontal row
 *   legend   — optional legend chips at the bottom
 *
 * Attributes
 *   compact  — tighter vertical spacing (for sub-trunks)
 *   tone     — cobalt | dim                (default: cobalt)
 *              tone="dim" colours connectors gray instead of cobalt
 *
 * The component renders only structure and connectors; the apex and
 * branch hexes are passed in as <cn-hex> (or anything you want) so
 * full styling control stays with the caller.
 */

class CnDomainTree extends HTMLElement {
  static get observedAttributes() { return ['compact', 'tone']; }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() { this.render(); }
  attributeChangedCallback() { if (this.shadowRoot) this.render(); }

  render() {
    const compact = this.hasAttribute('compact');
    const tone = this.getAttribute('tone') || 'cobalt';
    const connectorColor = tone === 'dim'
      ? 'var(--c-cobalt-100)'
      : 'var(--c-cobalt-200)';

    const gap = compact ? 'var(--space-3)' : 'var(--space-5)';
    const connectorH = compact ? '20px' : '32px';
    const padY = compact ? 'var(--space-5)' : 'var(--space-9)';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          font-family: var(--conduction-typography-font-family-body, system-ui, sans-serif);
        }
        .tree {
          display: flex; flex-direction: column;
          align-items: center;
          gap: ${gap};
          padding: ${padY} var(--space-7);
          overflow-x: auto;
        }
        .connector {
          width: 1px;
          height: ${connectorH};
          background: ${connectorColor};
          flex-shrink: 0;
        }
        .branches {
          display: flex;
          gap: var(--space-7);
          align-items: center;
          justify-content: center;
          flex-wrap: wrap;
        }
        .legend {
          display: flex; gap: var(--space-6);
          flex-wrap: wrap;
          margin-top: var(--space-4);
          padding-top: var(--space-5);
          border-top: 1px solid var(--c-cobalt-100);
          width: 100%;
          justify-content: center;
        }
        .legend:empty { display: none; }

        ::slotted([slot="legend"]) {
          display: flex; align-items: center; gap: var(--space-2);
          font-size: 13px;
          color: var(--c-cobalt-700);
        }
      </style>

      <div class="tree">
        <slot name="apex"></slot>
        <div class="connector"></div>
        <div class="branches"><slot></slot></div>
        <div class="legend"><slot name="legend"></slot></div>
      </div>
    `;
  }
}

if (!customElements.get('cn-domain-tree')) {
  customElements.define('cn-domain-tree', CnDomainTree);
}

export { CnDomainTree };
