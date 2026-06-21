/**
 * <cn-pair> — two systems linked by a bridge, with one orange arrow.
 *
 * The "system A talks to system B" diagram. Used at the top of every
 * integration / connector page where the relationship between two
 * systems is the headline. Extracted from the .pair-banner pattern
 * in preview/product-pages/integrations.html.
 *
 *   <cn-pair
 *     left-label="Open Register"
 *     left-caption="nextcloud.example"
 *     right-label="xWiki"
 *     right-caption="xwiki.example"
 *     right-color="cobalt-700"
 *     bridge-label="OpenConnector integration">
 *     <svg slot="left-icon" viewBox="0 0 24 24">...</svg>
 *     <svg slot="right-icon" viewBox="0 0 24 24">...</svg>
 *   </cn-pair>
 *
 * The bridge arrow is always KNVB orange — this component owns the
 * "one orange per scene" budget for the page. Don't place a second
 * <cn-pair> or a <cn-hex color="orange"> on the same screen.
 *
 * Slots
 *   left-icon   — SVG for the left hex (24×24 viewBox, currentColor stroke)
 *   right-icon  — SVG for the right hex
 *
 * Attributes
 *   left-label, right-label       — strong text under each hex
 *   left-caption, right-caption   — optional Plex Mono code-style caption
 *   left-color, right-color       — cobalt | cobalt-700 | nextcloud | commonground
 *                                   default: cobalt (left), cobalt-700 (right)
 *   bridge-label                  — text under the orange arrow
 *   arrow                         — glyph to render (default: ↔)
 */

const COLOR_MAP = {
  'cobalt':       'var(--c-blue-cobalt)',
  'cobalt-700':   'var(--c-cobalt-700)',
  'cobalt-900':   'var(--c-cobalt-900)',
  'nextcloud':    'var(--c-nextcloud-blue)',
  'commonground': 'var(--c-commonground-yellow)',
  'mint':         'var(--c-mint-500)',
  'lavender':     'var(--c-lavender-500)',
};

class CnPair extends HTMLElement {
  static get observedAttributes() {
    return [
      'left-label', 'left-caption', 'left-color',
      'right-label', 'right-caption', 'right-color',
      'bridge-label', 'arrow',
    ];
  }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() { this.render(); }
  attributeChangedCallback() { if (this.shadowRoot) this.render(); }

  render() {
    const leftLabel    = this.getAttribute('left-label')   || '';
    const leftCaption  = this.getAttribute('left-caption') || '';
    const leftColorKey = this.getAttribute('left-color')   || 'cobalt';
    const rightLabel   = this.getAttribute('right-label')   || '';
    const rightCaption = this.getAttribute('right-caption') || '';
    const rightColorKey= this.getAttribute('right-color')   || 'cobalt-700';
    const bridgeLabel  = this.getAttribute('bridge-label') || '';
    const arrow        = this.getAttribute('arrow') || '↔';

    const leftBg  = COLOR_MAP[leftColorKey]  || COLOR_MAP.cobalt;
    const rightBg = COLOR_MAP[rightColorKey] || COLOR_MAP['cobalt-700'];

    /* Common Ground yellow needs cobalt-900 ink for WCAG AA. Every
       other supported fill takes white. */
    const leftInk  = leftColorKey  === 'commonground' ? 'var(--c-cobalt-900)' : 'white';
    const rightInk = rightColorKey === 'commonground' ? 'var(--c-cobalt-900)' : 'white';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          font-family: var(--conduction-typography-font-family-body, system-ui, sans-serif);
        }

        .panel {
          display: grid;
          grid-template-columns: 1fr auto 1fr;
          align-items: center;
          gap: var(--space-5);
          padding: var(--space-6);
          background: var(--c-cobalt-50);
          border-radius: var(--radius-md);
        }

        .side {
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: var(--space-2);
          text-align: center;
        }

        .hex {
          width: 64px;
          height: 74px;
          clip-path: var(--hex-pointy-top);
          display: flex;
          align-items: center;
          justify-content: center;
        }
        .hex.left  { background: ${leftBg};  color: ${leftInk};  }
        .hex.right { background: ${rightBg}; color: ${rightInk}; }

        ::slotted(svg) {
          width: 30px;
          height: 30px;
        }

        .label {
          font-size: 16px;
          font-weight: 600;
          color: var(--c-cobalt-900);
        }
        .caption {
          font-family: var(--conduction-typography-font-family-code, ui-monospace, monospace);
          font-size: 11px;
          color: var(--c-cobalt-400);
          letter-spacing: 0.06em;
        }
        .caption:empty { display: none; }

        .bridge {
          font-family: var(--conduction-typography-font-family-code, ui-monospace, monospace);
          font-size: 12px;
          color: var(--c-cobalt-700);
          letter-spacing: 0.05em;
          text-align: center;
          max-width: 160px;
        }
        .bridge .arrow {
          display: block;
          font-size: 28px;
          color: var(--c-orange-knvb);
          line-height: 1;
          margin-bottom: 4px;
        }
        .bridge .arrow[aria-hidden="true"] + .bridge-label:empty { display: none; }

        @media (max-width: 700px) {
          .panel {
            grid-template-columns: 1fr;
            gap: var(--space-4);
          }
          .bridge .arrow { transform: rotate(90deg); }
        }
      </style>

      <div class="panel" role="figure" aria-label="${this._escape(leftLabel)} ↔ ${this._escape(rightLabel)}">
        <div class="side">
          <span class="hex left"><slot name="left-icon"></slot></span>
          <span class="label">${this._escape(leftLabel)}</span>
          <span class="caption">${this._escape(leftCaption)}</span>
        </div>
        <div class="bridge">
          <span class="arrow" aria-hidden="true">${this._escape(arrow)}</span>
          <span class="bridge-label">${this._escape(bridgeLabel)}</span>
        </div>
        <div class="side">
          <span class="hex right"><slot name="right-icon"></slot></span>
          <span class="label">${this._escape(rightLabel)}</span>
          <span class="caption">${this._escape(rightCaption)}</span>
        </div>
      </div>
    `;
  }

  _escape(s) {
    return String(s).replace(/[<>&"']/g, c => ({
      '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }
}

if (!customElements.get('cn-pair')) {
  customElements.define('cn-pair', CnPair);
}

export { CnPair };
