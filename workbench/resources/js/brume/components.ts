import { ChartCard } from "./components/ChartCard";
import { ComponentShowcase } from "./components/ComponentShowcase";
import { KpiRow } from "./components/KpiRow";
import { OrderStatus } from "./components/OrderStatus";

/**
 * The workbench's custom components, registered the way a `--frontend` project registers its own
 * (see the README, "Register a custom component"). PHP references them by these names.
 */
export const components = {
  KpiRow,
  ChartCard,
  ComponentShowcase,
  OrderStatus,
};

export { AddressField, AddressDisplay } from "./components/TaiwanAddress";
