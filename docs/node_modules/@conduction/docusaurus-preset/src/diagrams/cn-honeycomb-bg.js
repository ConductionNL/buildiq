/**
 * <cn-honeycomb-bg> — honeycomb backdrop wrapper.
 *
 * A reusable hex-pattern backdrop for any brand surface. Smaller
 * hexes form a regular tessellation; one optional orange accent
 * sits in a stable position. Always pointy-top, never flat-top,
 * never rotated.
 *
 *   <cn-honeycomb-bg theme="cobalt">
 *     <h2>Foreground content sits above the backdrop.</h2>
 *   </cn-honeycomb-bg>
 *
 * v1 ships a static SVG-pattern fill. Parallax + depth tiers come
 * later when we layer Motion One in.
 *
 * Attributes
 *   theme    — cobalt | light          (default: cobalt)
 *              cobalt = white hexes on cobalt-900 (default brand surface)
 *              light  = cobalt hexes on white (low-contrast inverse)
 *   accent   — boolean; renders one KNVB-orange hex accent in the field
 *   density  — sm | md | lg            (default: md)
 *              Hex tile size. Smaller = denser pattern.
 */

const DENSITIES = { sm: 24, md: 36, lg: 56 };

class CnHoneycombBg extends HTMLElement {
  static get observedAttributes() { return ['theme', 'accent', 'density']; }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() { this.render(); }
  attributeChangedCallback() { if (this.shadowRoot) this.render(); }

  render() {
    const theme = this.getAttribute('theme') || 'cobalt';
    const accent = this.hasAttribute('accent');
    const densityKey = this.getAttribute('density') || 'md';
    const r = DENSITIES[densityKey] || 36;
    const w = r * Math.sqrt(3);
    const h = r * 2;
    const stepY = h * 0.75;

    const isLight = theme === 'light';
    const bgColor = isLight ? '#FFFFFF' : 'var(--c-cobalt-900)';
    const hexColor = isLight ? 'var(--c-cobalt-100)' : 'rgba(255,255,255,0.06)';
    const hexStroke = isLight ? 'var(--c-cobalt-200)' : 'rgba(255,255,255,0.1)';

    /* Build a pattern with two hex rows for proper tessellation:
       even rows offset by 0; odd rows offset by w/2. */
    const patternW = w;
    const patternH = stepY * 2;
    const hexPath = (cx, cy) => {
      const points = [];
      for (let i = 0; i < 6; i++) {
        const angle = (Math.PI / 3) * i + Math.PI / 6; /* pointy-top */
        const x = cx + r * Math.cos(angle);
        const y = cy + r * Math.sin(angle);
        points.push(`${x.toFixed(2)},${y.toFixed(2)}`);
      }
      return points.join(' ');
    };

    /* Two staggered hex centres in one tile: top-row at y=stepY/2,
       bottom-row at y=stepY/2+stepY, offset by w/2. */
    const hexA = hexPath(0,         stepY / 2);
    const hexB = hexPath(w / 2,     stepY / 2 + stepY);

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          position: relative;
          background: ${bgColor};
          color: ${isLight ? 'var(--c-cobalt-900)' : 'white'};
          overflow: hidden;
          border-radius: var(--radius-lg);
        }

        svg.bg {
          position: absolute;
          inset: 0;
          width: 100%;
          height: 100%;
          z-index: 0;
          pointer-events: none;
        }

        ${accent ? `
          .accent {
            position: absolute;
            top: 22%;
            right: 14%;
            width: ${w * 1.2}px;
            height: ${h * 1.2}px;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            background: var(--c-orange-knvb);
            opacity: 0.85;
            z-index: 0;
            pointer-events: none;
          }
        ` : ''}

        .content {
          position: relative;
          z-index: 1;
          padding: var(--space-9) var(--space-7);
        }
      </style>

      <svg class="bg" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <pattern id="cn-honeycomb-pattern" width="${patternW}" height="${patternH}" patternUnits="userSpaceOnUse">
            <polygon points="${hexA}" fill="${hexColor}" stroke="${hexStroke}" stroke-width="1"/>
            <polygon points="${hexB}" fill="${hexColor}" stroke="${hexStroke}" stroke-width="1"/>
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#cn-honeycomb-pattern)"/>
      </svg>

      ${accent ? '<div class="accent"></div>' : ''}

      <div class="content"><slot></slot></div>
    `;
  }
}

if (!customElements.get('cn-honeycomb-bg')) {
  customElements.define('cn-honeycomb-bg', CnHoneycombBg);
}

export { CnHoneycombBg };
