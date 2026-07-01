import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Must match the Apache deploy folder URL on 50.12, or JS/CSS/API calls 404.
export default defineConfig({
  base: '/playground/doromal/projects-assistant-tool/',
  plugins: [react()],
  server: {
    port: 5173,
  },
});
