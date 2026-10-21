import { apiGet } from "@/lib/api/request";
import type { PriceOption } from "@/types/priceOptions";

/**
 * Fetches the distinct selling-price options for one product from the POS
 * price-options endpoint. A product has zero options when there is no eligible
 * batch stock at all, exactly one option when every eligible batch shares a
 * single selling price, or several when the batches carry different prices.
 */
export async function getPriceOptions(
  productId: number,
): Promise<PriceOption[]> {
  const response = await apiGet<{ product_id: number; options: PriceOption[] }>(
    `/inventory-batches/product/${productId}/price-options`,
  );
  return response.options;
}
