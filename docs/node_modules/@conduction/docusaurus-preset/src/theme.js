/**
 * @conduction/docusaurus-preset, theme plugin entry.
 *
 * Registers the brand theme with Docusaurus so swizzled components
 * (Navbar, Footer, future Doc / DocItem / etc.) replace the Infima
 * defaults automatically. Sites pick this up by including it in
 * themes: [...] (createConfig() does this for you).
 *
 * The brand CSS (token mapping + brand atoms) is also registered here
 * via getClientModules() so it loads on every page without the site
 * having to wire it into customCss.
 */

const path = require('path');

module.exports = function conductionTheme(context, options) {
  return {
    name: '@conduction/docusaurus-theme',

    /**
     * Tells Docusaurus where to find swizzleable theme components.
     * Anything under ./theme/<ComponentName>/ overrides the matching
     * Docusaurus default.
     */
    getThemePath() {
      return path.resolve(__dirname, './theme');
    },

    /**
     * Same path, exposed for the `docusaurus swizzle` CLI so consumers
     * can list / eject our overrides if they need to customise further.
     */
    getTypeScriptThemePath() {
      return path.resolve(__dirname, './theme');
    },

    /**
     * Brand CSS auto-loaded on every page. Sites can still append more
     * via customCss[]; the preset's createConfig() wires that through.
     */
    getClientModules() {
      return [path.resolve(__dirname, './css/brand.css')];
    },
  };
};
