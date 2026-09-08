/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './app/Filament/**/*.php',
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          navy: '#023b55',
          green: '#039652',
          'green-hover': '#027a42',
          gold: '#fcc023',
        },
        primary: '#039652',
        secondary: '#023b55',
      },
    },
  },
  plugins: [],
};
