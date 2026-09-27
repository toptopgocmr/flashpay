import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/admin.css', 'resources/js/admin/main.js'],
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls: {
          // Les chemins absolus (/images/...) pointent vers backend/public
          // et sont servis tels quels par Laravel : Vite ne doit pas les importer.
          base: null,
          includeAbsolute: false,
        },
      },
    }),
  ],
})
