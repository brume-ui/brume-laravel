import '@brume-ui/brume-tokens/tokens.css';
// Only react currently emits component styles; keep the other imports for compatibility.
import '@brume-ui/brume-react/styles.css';
import '@brume-ui/brume-renderer/styles.css';
import '@brume-ui/brume-inertia/styles.css';
import './brume.css';
import { createBrumeApp } from '@brume-ui/brume-inertia';
import { components } from './components';

createBrumeApp({
  mode: import.meta.env.VITE_BRUME_MODE ?? 'vite',
  components,
  pages: import.meta.glob('./pages/**/*.tsx'),
});
