import { Badge } from "@/components/ui/badge";

/**
 * Name of the role assigned to a user, rendered as a secondary pill.
 *
 * Reads the `roleName` snapshot stored on the user rather than joining to the
 * role catalogue on render — the same snapshot rule `Supplier.supplierName`
 * follows for purchases.
 */
export function UserRoleBadge({ roleName }: { roleName: string }) {
  return <Badge variant="secondary">{roleName}</Badge>;
}
