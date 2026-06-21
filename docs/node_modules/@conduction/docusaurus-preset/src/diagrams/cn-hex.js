/**
 * <cn-hex> — the foundational pointy-top hex primitive.
 *
 * Brand rules baked in: pointy-top only, solid colours, fixed palette.
 * No rotation, no gradients on the shape itself.
 *
 * Slots
 *   default   — label text (or any inline content)
 *   icon      — icon SVG / image, sits above the label by default
 *
 * Attributes
 *   color       cobalt | orange | mint | lavender | terracotta | forest |
 *               nextcloud | commonground | red
 *               (default: cobalt)
 *   size        sm | md | lg | xl  or any CSS length (default: md = 64px)
 *   variant     solid | outline      (default: solid)
 *   layout      vertical | horizontal (default: vertical, icon top + label below)
 *   interactive boolean — hover scale + focus ring + click event
 *
 * Geometry: the hex is rendered as an SVG polygon inside a 100x115.47 viewBox
 * (the natural 2/√3 height/width ratio of a regular pointy-top hex). The
 * label-content layer sits in an inner safe-area hex inscribed in the outer
 * shape, so labels and icons stay clear of the points.
 *
 * Ink colour: white on every brand fill except commonground (yellow), which
 * gets cobalt-900 ink so the label hits AA contrast against the saturated
 * yellow.
 */

const SIZES = { sm: 40, md: 64, lg: 96, xl: 128 };

const COLORS = {
  cobalt:       { fill: 'var(--c-blue-cobalt)',         ink: '#fff' },
  orange:       { fill: 'var(--c-orange-knvb)',         ink: '#fff' },
  mint:         { fill: 'var(--c-mint-500)',            ink: '#fff' },
  lavender:     { fill: 'var(--c-lavender-500)',        ink: '#fff' },
  terracotta:   { fill: 'var(--c-terracotta-500)',      ink: '#fff' },
  forest:       { fill: 'var(--c-forest-500)',          ink: '#fff' },
  nextcloud:    { fill: 'var(--c-nextcloud-blue)',      ink: '#fff' },
  commonground: { fill: 'var(--c-commonground-yellow)', ink: 'var(--c-cobalt-900)' },
  red:          { fill: 'var(--c-red-vermillion)',      ink: '#fff' },
};

class CnHex extends HTMLElement {
  static get observedAttributes() {
    return ['color', 'size', 'variant', 'layout', 'interactive'];
  }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    this.render();
    if (this.hasAttribute('interactive') && !this.hasAttribute('tabindex')) {
      this.setAttribute('tabindex', '0');
      this.setAttribute('role', 'button');
      this.addEventListener('keydown', this._onKey);
    }
  }

  disconnectedCallback() {
    this.removeEventListener('keydown', this._onKey);
  }

  attributeChangedCallback() {
    if (this.shadowRoot) this.render();
  }

  _onKey = (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      this.click();
    }
  };

  render() {
    const colorKey = this.getAttribute('color') || 'cobalt';
    const sizeAttr = this.getAttribute('size') || 'md';
    const variant = this.getAttribute('variant') || 'solid';
    const layout = this.getAttribute('layout') || 'vertical';
    const interactive = this.hasAttribute('interactive');

    const brand = COLORS[colorKey] || COLORS.cobalt;
    const sizePx = SIZES[sizeAttr] ? `${SIZES[sizeAttr]}px` : sizeAttr;

    const isOutline = variant === 'outline';
    const fill = isOutline ? 'transparent' : brand.fill;
    const stroke = isOutline ? brand.fill : 'transparent';
    const strokeW = isOutline ? 4 : 0;
    const ink = isOutline ? brand.fill : brand.ink;

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          --cn-hex-size: ${sizePx};
          --cn-hex-ink: ${ink};

          display: inline-flex;
          width: var(--cn-hex-size);
          height: calc(var(--cn-hex-size) * 1.1547);
          color: var(--cn-hex-ink);
          font-family: var(--conduction-typography-font-family-body, system-ui, sans-serif);
          font-weight: 600;
          font-size: calc(var(--cn-hex-size) * 0.16);
          line-height: 1.15;
          text-align: center;
          position: relative;
          outline: none;
          transition: transform 160ms ease;
        }

        ${interactive ? `
          :host { cursor: pointer; }
          :host(:hover) svg.shape  { transform: scale(1.04); }
          :host(:focus-visible) svg.shape polygon {
            stroke: var(--c-orange-knvb, #F36C21);
            stroke-width: 3;
          }
        ` : ''}

        svg.shape {
          position: absolute;
          inset: 0;
          width: 100%;
          height: 100%;
          overflow: visible;
          transition: transform 160ms ease;
        }
        svg.shape polygon {
          fill: ${fill};
          stroke: ${stroke};
          stroke-width: ${strokeW};
          stroke-linejoin: round;
          transition: fill 160ms ease, stroke 160ms ease;
        }

        .content {
          position: relative;
          width: 100%; height: 100%;
          display: flex;
          flex-direction: ${layout === 'horizontal' ? 'row' : 'column'};
          align-items: center;
          justify-content: center;
          gap: ${layout === 'horizontal' ? '6%' : '4%'};
          padding: ${layout === 'horizontal' ? '0 20%' : '22% 16%'};
          box-sizing: border-box;
        }

        ::slotted([slot="icon"]) {
          width: ${layout === 'horizontal' ? '34%' : '42%'};
          height: auto;
          flex-shrink: 0;
          color: currentColor;
          fill: currentColor;
        }

        .label {
          display: inline-block;
          letter-spacing: -0.005em;
          word-break: keep-all;
        }
      </style>

      <svg class="shape" viewBox="0 0 100 115.47" preserveAspectRatio="none" aria-hidden="true">
        <polygon points="50,2 98,28.87 98,86.6 50,113.47 2,86.6 2,28.87" />
      </svg>
      <div class="content">
        <slot name="icon"></slot>
        <span class="label"><slot></slot></span>
      </div>
    `;
  }
}

if (!customElements.get('cn-hex')) {
  customElements.define('cn-hex', CnHex);
}

export { CnHex };
