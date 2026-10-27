import type { ComponentType } from 'react';

/**
 * Custom components that PHP schemas can reference by name, e.g.:
 *
 *   import { StockBadge } from './components/StockBadge';
 *   export const components = { StockBadge };
 */
// biome-ignore lint/suspicious/noExplicitAny: custom components define their own props
export const components: Record<string, ComponentType<any>> = {};
