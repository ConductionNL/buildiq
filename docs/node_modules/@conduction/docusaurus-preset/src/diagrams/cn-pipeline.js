/**
 * <cn-pipeline> — horizontal flow of hexes connected by arrows.
 *
 * The flattened cousin of cn-platform. Used in solution pages, how-
 * it-works sections, and any sequence diagram where data moves
 * left-to-right through a series of stages.
 *
 *   <cn-pipeline>
 *     <cn-hex color="lavender">Source</cn-hex>
 *     <cn-hex color="forest">Process</cn-hex>
 *     <cn-hex color="cobalt">Workspace</cn-hex>
 *     <cn-hex color="mint">Sink</cn-hex>
 *   </cn-pipeline>
 *
 * The component reads its own light-DOM children, assigns each to a
 * numbered slot, and inserts an arrow connector between consecutive
 * pairs. Re-renders when children change.
 *
 * Slots
 *   default  — pipeline stages, in left-to-right order
 *   caption  — optional small text under the row
 *
 * Attributes
 *   tone     — dotted | solid | dim   (default: dotted)
 *              Arrow style.
 *   align    — start | center | end   (default: center)
 *              Vertical alignment of stages along the baseline.
 */

class CnPipeline extends HTMLElement {
  static get observedAttributes() { return ['tone', 'align']; }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    this.render();
    this._observer = new MutationObserver(() => this.render());
    this._observer.observe(this, { childList: true });
  }

  disconnectedCallback() {
    if (this._observer) this._observer.disconnect();
  }

  attributeChangedCallback() { if (this.shadowRoot) this.render(); }

  render() {
    const tone = this.getAttribute('tone') || 'dotted';
    const align = this.getAttribute('align') || 'center';

    /* Assign each light-DOM child a unique slot name so we can
       interleave arrows between them in the shadow DOM. Skip the
       caption slot. */
    const stages = [...this.children].filter(
      el => el.getAttribute('slot') !== 'caption'
    );
    stages.forEach((el, i) => { el.setAttribute('slot', `step-${i}`); });

    const arrowDash = tone === 'solid' ? 'none'
                    : tone === 'dim'    ? '2 4'
                                        : '4 6';
    const arrowColor = tone === 'dim' ? 'var(--c-cobalt-200)' : 'var(--c-cobalt-400)';

    const arrowSvg = `
      <svg class="arrow" viewBox="0 0 60 12" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <line x1="0" y1="6" x2="48" y2="6" stroke="${arrowColor}" stroke-width="1.5"
              stroke-linecap="round"
              ${arrowDash !== 'none' ? `stroke-dasharray="${arrowDash}"` : ''}/>
        <polyline points="42,1 50,6 42,11" fill="none" stroke="${arrowColor}"
                  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    `.trim();

    let pipelineMarkup = '';
    for (let i = 0; i < stages.length; i++) {
      pipelineMarkup += `<slot name="step-${i}"></slot>`;
      if (i < stages.length - 1) {
        pipelineMarkup += arrowSvg;
      }
    }

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          font-family: var(--conduction-typography-font-family-body, system-ui, sans-serif);
        }

        .stage {
          background: var(--c-cobalt-50);
          border-radius: var(--radius-lg);
          padding: var(--space-9) var(--space-7);
          overflow-x: auto;
        }

        .row {
          display: flex;
          align-items: ${align === 'start' ? 'flex-start' : align === 'end' ? 'flex-end' : 'center'};
          justify-content: center;
          gap: var(--space-3);
          flex-wrap: nowrap;
          min-height: 200px;
        }

        .arrow {
          width: 60px;
          height: 12px;
          flex-shrink: 0;
        }

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
          .row { flex-wrap: wrap; }
          .arrow { transform: rotate(90deg); width: 40px; }
        }
      </style>

      <div class="stage">
        <div class="row">
          ${pipelineMarkup}
        </div>
        <div class="caption"><slot name="caption"></slot></div>
      </div>
    `;
  }
}

if (!customElements.get('cn-pipeline')) {
  customElements.define('cn-pipeline', CnPipeline);
}

export { CnPipeline };
