"use client";

import { useEffect, useMemo, useState } from "react";

import { Badge } from "@/components/ui/badge";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import { InventoryStats } from "@/features/inventory/components/InventoryStats";
import { InventoryTable } from "@/features/inventory/components/InventoryTable";
import { StockAdjustmentDialog } from "@/features/inventory/components/StockAdjustmentDialog";
import { StockMovementList } from "@/features/inventory/components/StockMovementList";
import { summarizeInventory } from "@/features/inventory/lib/stock";
import { isStockTracked } from "@/features/products/lib/products";
import {
  selectCategories,
  selectProducts,
  useCatalogueStore,
} from "@/stores/useProductStore";
import { selectMovements, useInventoryStore } from "@/stores/useInventoryStore";
import type { Product } from "@/types/product";

/** Which stock slice the table is showing. */
type InventoryTab = "all" | "low" | "out";

interface TabCountProps {
  count: number;
  /** Colour the pill while the count is above zero (neutral otherwise). */
  tone?: "warning" | "destructive";
}

/** Count pill inside a tab trigger. */
function TabCount({ count, tone }: TabCountProps) {
  if (count === 0 || tone === undefined) {
    return <Badge variant="secondary">{count}</Badge>;
  }
  if (tone === "warning") {
    return (
      <Badge
        variant="outline"
        className="border-warning/50 bg-warning/10 text-warning"
      >
        {count}
      </Badge>
    );
  }
  return <Badge variant="destructive">{count}</Badge>;
}

/**
 * `/inventory` screen — composition only (Phase 7).
 *
 * Conceptual split from products: this screen answers "how much stock is
 * there and how did it change" (summary → tabs → adjustment → history), while
 * `features/products` answers "what is the product". One memoised
 * `summarizeInventory` pass derives both the stat cards and the low/out tab
 * lists; an adjustment writes the product store (quantity) plus the movement
 * store (history) through the stock service, and every panel updates from
 * there — no extra global state. The dialog mounts only while open.
 */
export function InventoryScreen() {
  const products = useCatalogueStore(selectProducts);
  const categories = useCatalogueStore(selectCategories);
  const movements = useInventoryStore(selectMovements);
  const isLoadingMovements = useInventoryStore((state) => state.isLoading);
  const hasMoreMovements = useInventoryStore((state) => state.hasMore);
  const loadMovements = useInventoryStore((state) => state.load);

  const [tab, setTab] = useState<InventoryTab>("all");
  const [adjustTarget, setAdjustTarget] = useState<Product | null>(null);

  // Two independent loads on mount: the catalogue (ledger balances) and the
  // movement log. The ledger is not loaded until the catalogue exists, because
  // the names it prints are resolved from it — hence the dependency below.
  const isLoaded = useCatalogueStore((state) => state.isLoaded);
  const catalogueLoading = useCatalogueStore((state) => state.isLoading);
  const hydrate = useCatalogueStore((state) => state.hydrate);

  useEffect(() => {
    if (!isLoaded && !catalogueLoading) {
      void hydrate();
    }
  }, [hydrate, isLoaded, catalogueLoading]);

  useEffect(() => {
    if (isLoaded) {
      void loadMovements({}, 1);
    }
  }, [isLoaded, loadMovements]);

  const summary = useMemo(() => summarizeInventory(products), [products]);
  const trackedProducts = useMemo(
    () => products.filter(isStockTracked),
    [products],
  );

  return (
    <section aria-label="Inventory" className="flex min-w-0 flex-1 flex-col gap-4">
      <InventoryStats summary={summary} />

      <Tabs
        value={tab}
        onValueChange={(value) => setTab(value as InventoryTab)}
      >
        <TabsList>
          <TabsTrigger value="all">
            All <TabCount count={summary.trackedCount} />
          </TabsTrigger>
          <TabsTrigger value="low">
            Low stock <TabCount count={summary.lowStock.length} tone="warning" />
          </TabsTrigger>
          <TabsTrigger value="out">
            Out of stock{" "}
            <TabCount count={summary.outOfStock.length} tone="destructive" />
          </TabsTrigger>
        </TabsList>

        <TabsContent value="all">
          <InventoryTable
            products={trackedProducts}
            categories={categories}
            onAdjust={setAdjustTarget}
            emptyTitle="No products yet"
            emptyHint="Add products in the Products screen to track their stock."
          />
        </TabsContent>
        <TabsContent value="low">
          <InventoryTable
            products={summary.lowStock}
            categories={categories}
            onAdjust={setAdjustTarget}
            emptyTitle="No low stock products"
            emptyHint="Every product is above its low-stock threshold."
          />
        </TabsContent>
        <TabsContent value="out">
          <InventoryTable
            products={summary.outOfStock}
            categories={categories}
            onAdjust={setAdjustTarget}
            emptyTitle="No out-of-stock products"
            emptyHint="Every tracked product has stock on hand."
          />
        </TabsContent>
      </Tabs>

      <StockMovementList
        movements={movements}
        isLoading={isLoadingMovements}
        hasMore={hasMoreMovements}
        onLoadMore={() => {
          void loadMovements({}, useInventoryStore.getState().page + 1);
        }}
      />

      {adjustTarget !== null && (
        <StockAdjustmentDialog
          product={adjustTarget}
          onOpenChange={(open) => {
            if (!open) {
              setAdjustTarget(null);
            }
          }}
        />
      )}
    </section>
  );
}