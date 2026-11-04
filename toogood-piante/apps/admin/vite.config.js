import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Porta fissa: l'API accetta richieste cross-origin solo da questa (vedi apps/api/config/cors.php).
export default defineConfig({
  plugins: [react()],
  server: { port: 5173, strictPort: true },
});
