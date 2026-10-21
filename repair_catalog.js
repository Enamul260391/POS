const fs = require('fs');
const path = 'frontend/features/pos/lib/catalog.ts';
let content = fs.readFileSync(path, 'utf8');
let changed = false;

// 1. Add 'price-selection-required' case to describeCartFailure
const describeOld = `    case "item-not-found":
      return "That cart line no longer exists.";
  }
}`;
const describeNew = `    case "item-not-found":
      return "That cart line no longer exists.";
    case "price-selection-required":
      return "This product has multiple prices. Pick one before adding.";
  }
}`;
if (content.includes(describeOld)) {
  content = content.replace(describeOld, describeNew);
  console.log('OK: Added price-selection-required case to describeCartFailure');
  changed = true;
} else {
  console.log('SKIP: describeCartFailure insertion point not found (may already be present)');
}

// 2. Replace the broken addProductToSaleWithPriceOptions function
// The function was written with literal backslash-n characters instead of real newlines
const brokenStart = String.raw`/**\n * Adds a product while the POS resolves its batch price options first.`;
const brokenEnd = String.raw`return { ok: false, message: describeCartFailure("price-selection-required") };\n}`;

const startIdx = content.indexOf(brokenStart);
const endIdx = content.indexOf(brokenEnd, startIdx);

if (startIdx === -1) {
  console.log('SKIP: Broken function start not found (may already be repaired)');
} else if (endIdx === -1) {
  console.log('ERROR: Broken function end not found');
} else {
  const endOfFunction = endIdx + brokenEnd.length;
  const correctFunction = `/**
 * Adds a product while the POS resolves its batch price options first.
 *
 * Three outcomes, exactly matching the backend \`resolveBatchLine\` contract:
 *
 *   - zero eligible batches → never reaches here: \`canAddProduct\` already
 *     blocks the add. The caller shows "out of stock".
 *   - one distinct price   → auto-selected, no selector, no \`batch_id\`.
 *   - several distinct     → the caller must let the cashier choose before
 *     any line is added.
 */
export async function addProductToSaleWithPriceOptions(
  product: Product | null | undefined,
  quantity = 1,
  onSelect?: (productId: number, options: PriceOption[], quantity: number) => void,
): Promise<AddToSaleOutcome> {
  if (product === null || product === undefined) {
    return { ok: false, message: describeCartFailure("invalid-product") };
  }
  if (product.status !== "active") {
    return { ok: false, message: describeCartFailure("product-not-active") };
  }

  // Zero-stock products are blocked by the same stock gate used by every
  // other add path; do not fetch options for a product the cashier cannot
  // sell at all.
  const store = useCartStore.getState();
  const inCartQuantity = cartQuantityOf(store.items, product.id);
  if (!canAddProduct(product, inCartQuantity, quantity)) {
    const remaining = remainingStock(product, inCartQuantity) ?? 0;
    return {
      ok: false,
      message:
        remaining <= 0
          ? \`\${product.name} is out of stock.\`
          : \`Only \${remaining} left in stock.\`,
    };
  }

  const options = await getPriceOptions(product.id);

  // \`options.length === 0\` can only happen when the backend's fallback
  // logic collapsed to nothing; treat it as a zero-stock guard.
  if (options.length === 0) {
    return { ok: false, message: \`\${product.name} is out of stock.\` };
  }

  if (options.length === 1) {
    // Auto-select the single selling price. The backend splits across
    // batches with FIFO, so no \`batch_id\` is needed on the client.
    useCartStore.getState().addProduct(product, {
      quantity,
      unitPrice: Number(options[0].selling_price),
      batchId: null,
    });
    return { ok: true, product };
  }

  // Several distinct prices: hand the selection to the caller so the
  // cashier can choose before any line is written.
  onSelect?.(product.id, options, quantity);
  return { ok: false, message: describeCartFailure("price-selection-required") };
}`;
  content = content.slice(0, startIdx) + correctFunction + content.slice(endOfFunction);
  console.log('OK: Repaired addProductToSaleWithPriceOptions function');
  changed = true;
}

if (changed) {
  fs.writeFileSync(path, content, 'utf8');
  console.log('File written successfully');
} else {
  console.log('No changes made');
}
