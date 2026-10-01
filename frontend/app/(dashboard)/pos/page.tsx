import type { Metadata } from "next";

import { PosTerminal } from "@/features/pos/components/PosTerminal";

export const metadata: Metadata = {
  title: "POS",
  description: "Point of sale terminal.",
};

/**
 * `/pos` — the cashier terminal.
 *
 * Deliberately tiny: layout, catalog and cart composition live in
 * `PosTerminal`, keeping the page component free of POS logic.
 */
export default function PosPage() {
  return (
    <>
      <h1 className="sr-only">Point of sale terminal</h1>
      <PosTerminal />
    </>
  );
}
