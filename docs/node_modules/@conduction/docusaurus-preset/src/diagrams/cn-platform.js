/**
 * <cn-platform> — workspace-plus-orbiting-apps cluster.
 *
 * The canonical "what is ConNext" diagram: a Nextcloud workspace at
 * the centre, six application hexes arranged in a hex ring around it.
 * Used on landing pages, product overviews, ecosystem visuals.
 *
 * Slots
 *   apex     — central element; required (typically a cobalt cn-hex).
 *              Same slot name as cn-domain-tree; "kernel" is a banned
 *              word in the brand vocabulary, see identity/voice.html.
 *   default  — orbiting apps; up to six, positioned in this order:
 *              top-left, top-right, left, right, bottom-left, bottom-right
 *   caption  — optional small text under the cluster
 *
 * Attributes
 *   ground   — boolean; renders a soft platform shadow under the cluster
 *   tone     — light | cobalt-50 | white       (default: cobalt-50)
 *              Background of the surrounding stage.
 *
 * The component owns only layout + ground + optional connector ring.
 * Each child element keeps its own styling. Brand is flat-hex only;
 * the 3D prism was removed in v3.0.0.
 */

class CnPlatform extends HTMLElement {
  static get observedAttributes() { return ['ground', 'tone']; }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() { this.render(); }
  attributeChangedCallback() { if (this.shadowRoot) this.render(); }

  render() {
    const ground = this.hasAttribute('ground');
    const tone = this.getAttribute('tone') || 'cobalt-50';

    const stageBg = tone === 'white'
      ? 'white'
      : tone === 'light'
      ? 'var(--c-cobalt-100)'
      : 'var(--c-cobalt-50)';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          font-family: var(--conduction-typography-font-family-body, system-ui, sans-serif);
        }

        .stage {
          position: relative;
          background: ${stageBg};
          border-radius: var(--radius-lg);
          padding: var(--space-9) var(--space-7);
          overflow: hidden;
        }

        .grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          grid-template-rows: repeat(3, auto);
          gap: var(--space-3) var(--space-4);
          justify-items: center;
          align-items: center;
          position: relative;
          z-index: 1;
        }

        /* Apex (workspace) sits dead centre */
        ::slotted([slot="apex"]) {
          grid-column: 3;
          grid-row: 2;
        }

        /* Six orbiting apps — assigned by nth-child of the default slot */
        ::slotted(*:not([slot])) {
          /* default cells fall outside the grid; positioned per-index below */
        }
        ::slotted(:nth-child(1):not([slot])) { grid-column: 2; grid-row: 1; }
        ::slotted(:nth-child(2):not([slot])) { grid-column: 4; grid-row: 1; }
        ::slotted(:nth-child(3):not([slot])) { grid-column: 1; grid-row: 2; }
        ::slotted(:nth-child(4):not([slot])) { grid-column: 5; grid-row: 2; }
        ::slotted(:nth-child(5):not([slot])) { grid-column: 2; grid-row: 3; }
        ::slotted(:nth-child(6):not([slot])) { grid-column: 4; grid-row: 3; }

        ${ground ? `
          .ground {
            position: absolute;
            bottom: 8%;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 28px;
            background: radial-gradient(ellipse at center,
              rgba(33, 70, 139, 0.18) 0%,
              transparent 70%);
            z-index: 0;
            pointer-events: none;
          }
        ` : ''}

        .caption {
          margin-top: var(--space-5);
          font-family: var(--conduction-typography-font-family-code, ui-monospace, monospace);
          font-size: 11px;
          letter-spacing: 0.08em;
          text-transform: uppercase;
          color: var(--c-cobalt-400);
          text-align: center;
        }
        ::slotted([slot="caption"]) {
          font-family: var(--conduction-typography-font-family-code, ui-monospace, monospace);
          font-size: 11px;
          letter-spacing: 0.08em;
          color: var(--c-cobalt-400);
        }

        @media (max-width: 700px) {
          .grid {
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto;
          }
          ::slotted([slot="apex"]) { grid-column: 1 / -1; grid-row: 1; }
          ::slotted(:nth-child(1):not([slot])),
          ::slotted(:nth-child(2):not([slot])),
          ::slotted(:nth-child(3):not([slot])),
          ::slotted(:nth-child(4):not([slot])),
          ::slotted(:nth-child(5):not([slot])),
          ::slotted(:nth-child(6):not([slot])) {
            grid-column: auto;
            grid-row: auto;
          }
        }
      </style>

      <div class="stage">
        ${ground ? '<div class="ground"></div>' : ''}
        <div class="grid">
          <slot name="apex"></slot>
          <slot></slot>
        </div>
        <div class="caption"><slot name="caption"></slot></div>
      </div>
    `;
  }
}

if (!customElements.get('cn-platform')) {
  customElements.define('cn-platform', CnPlatform);
}

export { CnPlatform };
