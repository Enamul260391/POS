import { Badge } from "@/components/ui/badge";
import type { UserStatus } from "@/types/user";

/** Visual style per account status, shared by table and details. */
const STATUS_STYLES: Readonly<
  Record<UserStatus, { label: string; className: string }>
> = {
  active: {
    label: "Active",
    className: "border-success/40 bg-success/10 text-success",
  },
  inactive: {
    label: "Inactive",
    className: "border-warning/40 bg-warning/10 text-warning",
  },
};

/**
 * Account status pill of a user (Phase 13).
 *
 * Mirrors `PaymentStatusBadge` and the customer `StatusBadge`: a single source
 * of truth so the table and the details dialog never disagree on wording or
 * colour.
 */
export function UserStatusBadge({ status }: { status: UserStatus }) {
  const style = STATUS_STYLES[status];
  return (
    <Badge variant="outline" className={style.className}>
      {style.label}
    </Badge>
  );
}
