import { ComponentShowcase } from './components/ComponentShowcase';
import { DemoChart } from './components/DemoChart';
import { OrderStatus } from './components/OrderStatus';

/**
 * The workbench's custom components, registered the way a `--frontend` project registers its own
 * (see the README, "Register a custom component"). PHP references them by these names.
 */
export const components = { 'demo-chart': DemoChart, ComponentShowcase, OrderStatus };
