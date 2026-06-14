/**
 * <cn-side-box> — the rectangle-feed-hex pattern.
 *
 * Hexes are "the system". Side-boxes are "what feeds it" or
 * "what consumes it". The shape difference is the hierarchy.
 *
 *   <cn-side-box header="YOUR DATA">
 *     <cn-side-row icon="..." label="Spreadsheets"/>
 *     <cn-side-row icon="..." label="Databases"/>
 *     <cn-side-row icon="..." label="REST & SOAP"/>
 *     <cn-side-row icon="..." label="Legacy systems"/>
 *   </cn-side-box>
 *
 * Slots
 *   default  — rows of icon + label
 *   footer   — optional "+ N more" line
 *
 * Attributes
 *   header   — string; the small uppercase chip header
 *   compact  — boolean; tighter row padding for dense diagrams
 *   width    — sm (180px) | md (220px) | lg (260px)   (default: md)
 */

const WIDTHS = { sm: 180, md: 220, lg: 260 };

class CnSideBox extends HTMLElement {
  static get observedAttributes() { return ['header', 'compact', 'width']; }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() { this.render(); }
  attributeChangedCallback() { if (this.shadowRoot) this.render(); }

  render() {
    const header = this.getAttribute('header') || '';
    const compact = this.hasAttribute('compact');
    const widthAttr = this.getAttribute('width') || 'md';
    const widthPx = WIDTHS[widthAttr] ? `${WIDTHS[widthAttr]}px` : widthAttr;

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: inline-block;
          width: ${widthPx};
          background: white;
          border: 1px solid var(--c-cobalt-200);
          border-radius: var(--radius-md);
          padding: ${compact ? '10px 10px 12px' : '14px 14px 16px'};
          font-family: var(--conduction-typography-font-family-body, system-ui, sans-serif);
        }

        .chip {
          font-family: var(--conduction-typography-font-family-code, ui-monospace, monospace);
          font-size: 10px;
          letter-spacing: 0.16em;
          text-transform: uppercase;
          color: var(--c-cobalt-400);
          margin-bottom: ${compact ? '8px' : '12px'};
        }
        .chip:empty { display: none; }

        ::slotted(*) {
          display: grid;
          grid-template-columns: ${compact ? '20px' : '28px'} 1fr;
          gap: ${compact ? '8px' : '12px'};
          align-items: center;
          padding: ${compact ? '4px 0' : '6px 0'};
          font-size: ${compact ? '12px' : '13px'};
          color: var(--c-cobalt-700);
        }

        ::slotted([slot="footer"]) {
          margin-top: ${compact ? '6px' : '8px'};
          padding-top: ${compact ? '6px' : '8px'};
          border-top: 1px dashed var(--c-cobalt-200);
          font-family: var(--conduction-typography-font-family-code, ui-monospace, monospace);
          font-size: 11px;
          color: var(--c-cobalt-400);
          text-align: center;
          display: block;
        }
      </style>

      <div class="chip">${this._escape(header)}</div>
      <slot></slot>
      <slot name="footer"></slot>
    `;
  }

  _escape(s) {
    return String(s).replace(/[<>&"']/g, c => ({
      '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }
}

if (!customElements.get('cn-side-box')) {
  customElements.define('cn-side-box', CnSideBox);
}

export { CnSideBox };
