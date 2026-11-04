import "@brume-ui/brume-tokens/tokens.css";
import "@brume-ui/brume-react/styles.css";
import "@brume-ui/brume-renderer/styles.css";
import "@brume-ui/brume-inertia/styles.css";
import "./brume.css";
import { createBrumeApp } from "@brume-ui/brume-inertia";
import { AddressDisplay, AddressField, components } from "./components";
import { PromotionCalculator } from "./components/PromotionCalculator";

createBrumeApp({
  mode: import.meta.env.VITE_BRUME_MODE ?? "vite",
  components,
  fields: {
    TaiwanAddress: { component: AddressField, inlineEditable: true },
    PromotionCalculator: { component: PromotionCalculator },
  },
  displays: { TaiwanAddress: AddressDisplay },
  pages: import.meta.glob("./pages/**/*.tsx"),
});
