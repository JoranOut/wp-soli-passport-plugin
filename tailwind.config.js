/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './includes/**/*.php',
    './templates/**/*.php',
  ],
  theme: {
    extend: {},
  },
  plugins: [
    require('daisyui'),
  ],
  daisyui: {
    themes: ['light'],
    prefix: '',
  },
  // Prefix Tailwind classes to avoid conflicts with WordPress admin
  prefix: 'tw-',
  corePlugins: {
    preflight: false, // Disable Tailwind's reset to avoid conflicts with WP admin
  },
}
