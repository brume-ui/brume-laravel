import '@herb09638/brume-tokens/tokens.css';
import '@herb09638/brume-react/styles.css';
import '@herb09638/brume-renderer/styles.css';
import '@herb09638/brume-inertia/styles.css';
import './brume.css';
import { createBrumeApp } from '@herb09638/brume-inertia';
import { components } from './components';

createBrumeApp({
  mode: import.meta.env.VITE_BRUME_MODE ?? 'vite',
  components,
  pages: import.meta.glob('./pages/**/*.tsx'),
});
