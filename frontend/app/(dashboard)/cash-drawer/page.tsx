import type { Metadata } from "next";

import { CashDrawerScreen } from "@/features/cashDrawer/components/CashDrawerScreen";

export const metadata: Metadata = {
  title: "Cash Drawer",
  description: "Register opening balance, cash movements and closing count.",
};

/** `/cash-drawer` — mock register session management (Phase 11). */
export default function CashDrawerPage() {
  return (
    <>
      <h1 className="sr-only">Cash Drawer</h1>
      <CashDrawerScreen />
    </>
  );
}
