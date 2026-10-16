/** FPanel CSS build. The palette is extracted from the old bundle so the design does not drift. */
module.exports = {
  darkMode: 'class',
  content: [
    '../app/views/**/*.php',
    '../app/public/assets/js/**/*.js',
    '../web/mockup/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50:  '#F0FDFA', 100: '#CCFBF1', 200: '#99F6E4',
          300: '#5EEAD4', 400: '#2DD4BF', 500: '#14B8A6',
          600: '#0D9488', 700: '#0F766E', 800: '#115E59',
        },
        navy: { DEFAULT: '#0B1B34', line: '#1E3A5F' },
      },
      boxShadow: {
        card: '0 1px 2px rgba(15,23,42,.05), 0 1px 3px rgba(15,23,42,.07)',
        pop:  '0 12px 32px -10px rgba(15,23,42,.28)',
      },
    },
  },
  plugins: [],
};
