/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // Backed by CSS variables in index.css → recolor with the theme switch.
        surface: 'var(--surface)',
        surface2: 'var(--surface-2)',
        line: 'var(--border)',
        ink: 'var(--text)',
        muted: 'var(--text-muted)',
        brand: 'var(--primary)',
        'brand-dark': 'var(--primary-dark)',
        headerbg: 'var(--header-bg)',
      },
    },
  },
  plugins: [],
};
