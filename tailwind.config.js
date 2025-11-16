/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './template/**/*.html',
    './asset/script/js/*.js'
  ],
  safelist: [
    'rounded-[40px]',
  ],
  theme: {
    extend: {
      borderRadius: {
        '4xl': '40px',
      }
    }
  },
  plugins: [],
};