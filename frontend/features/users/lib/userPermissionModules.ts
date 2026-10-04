import type { Permission, PermissionKey } from "@/types/permission";

/**
 * Display grouping of the permission catalogue for the Add user editor.
 *
 * This is a **presentation layer only**. Every entry names real catalogue slugs
 * exactly as `PermissionService` defines them, and a slug the catalogue does not
 * contain is dropped rather than invented — nothing here can put a permission on
 * screen that the API did not issue, nor rename one the backend authorises on.
 *
 * Two consequences of reading the catalogue rather than restating it are worth
 * spelling out, because they are deliberate:
 *
 *  - **No `dashboard.*` slug exists.** `PermissionService::REPORTS_VIEW` covers
 *    the dashboard ("the reporting suite (dashboard, sales, purchases, …)"), so
 *    there is no dashboard capability to tick. Under the "no fake slugs" rule the
 *    Dashboard module therefore has nothing to render and is absent from the
 *    editor, rather than being faked out of `reports.view`.
 *  - **Not every module is CRUD.** `inventory.adjust` (Stock Adjust),
 *    `sales.payments.create` (Payment) and `sale_returns.*` (Return) are real
 *    capabilities that are not create/update/delete, so those modules show the
 *    capability the catalogue defines instead of an invented "Delete".
 *
 *  - **Cash Registers uses shift vocabulary, not CRUD verbs.** Opening a till and
 *    closing it are the two ends of a cashier's shift, so `cash_registers.create`
 *    and `cash_registers.update` are displayed as "Open" and "Close". The label
 *    is presentation only — the slug the API authorises on is untouched, and
 *    nothing here renames or re-points a backend capability.
 *
 * A slug present in the catalogue but absent from {@link MODULE_SPECS} is still
 * rendered, under "Other", so a future reseed can never hide a capability from
 * the administrator.
 */

/** One child row: a display label and the slugs it stands for. */
interface PermissionModuleChildSpec {
  label: string;
  keys: readonly PermissionKey[];
}

/** One module header and the capabilities grouped under it. */
interface PermissionModuleSpec {
  /** Stable key for React. */
  id: string;
  label: string;
  /**
   * The capabilities under this header. A module that resolves to a single
   * capability renders without child rows — the header checkbox *is* that
   * capability, so a child row would only repeat it.
   */
  children: readonly PermissionModuleChildSpec[];
}

/** Label of the catch-all module for capabilities no spec claims. */
const OTHER_MODULE_LABEL = "Other";

/** The canonical capability layout an administrator works with. */
const MODULE_SPECS: readonly PermissionModuleSpec[] = [
  {
    id: "products",
    label: "Products",
    children: [
      { label: "View", keys: ["products.view"] },
      { label: "Create", keys: ["products.create"] },
      { label: "Update", keys: ["products.update"] },
      { label: "Delete", keys: ["products.delete"] },
    ],
  },
  {
    id: "product-categories",
    label: "Products Categories",
    children: [
      { label: "View", keys: ["categories.view"] },
      { label: "Create", keys: ["categories.create"] },
      { label: "Update", keys: ["categories.update"] },
      { label: "Delete", keys: ["categories.delete"] },
    ],
  },
  {
    id: "inventory",
    label: "Inventory",
    children: [
      { label: "View", keys: ["inventory.view"] },
      { label: "Stock Adjust", keys: ["inventory.adjust"] },
    ],
  },
  {
    id: "sales",
    label: "Sales",
    children: [
      { label: "View", keys: ["sales.view"] },
      { label: "Create", keys: ["sales.create"] },
      { label: "Update", keys: ["sales.update"] },
      { label: "Delete", keys: ["sales.delete"] },
      { label: "Payment", keys: ["sales.payments.create"] },
      { label: "Return", keys: ["sale_returns.view", "sale_returns.create"] },
    ],
  },
  {
    id: "purchases",
    label: "Purchases",
    children: [
      { label: "View", keys: ["purchases.view"] },
      { label: "Create", keys: ["purchases.create"] },
      { label: "Update", keys: ["purchases.update"] },
      { label: "Delete", keys: ["purchases.delete"] },
    ],
  },
  {
    id: "customers",
    label: "Customers",
    children: [
      { label: "View", keys: ["customers.view"] },
      { label: "Create", keys: ["customers.create"] },
      { label: "Update", keys: ["customers.update"] },
      { label: "Delete", keys: ["customers.delete"] },
    ],
  },
  {
    id: "suppliers",
    label: "Suppliers",
    children: [
      { label: "View", keys: ["suppliers.view"] },
      { label: "Create", keys: ["suppliers.create"] },
      { label: "Update", keys: ["suppliers.update"] },
      { label: "Delete", keys: ["suppliers.delete"] },
    ],
  },
  {
    id: "expenses",
    label: "Expenses",
    children: [
      { label: "View", keys: ["expenses.view"] },
      { label: "Create", keys: ["expenses.create"] },
      { label: "Update", keys: ["expenses.update"] },
      { label: "Delete", keys: ["expenses.delete"] },
    ],
  },
  {
    id: "expense-categories",
    label: "Expenses Categories",
    children: [
      { label: "View", keys: ["expense_categories.view"] },
      { label: "Create", keys: ["expense_categories.create"] },
      { label: "Update", keys: ["expense_categories.update"] },
      { label: "Delete", keys: ["expense_categories.delete"] },
    ],
  },
  {
    id: "cash-registers",
    label: "Cash Registers",
    // Shift vocabulary, not CRUD verbs: the slugs stay exactly as
    // `PermissionService` defines them, only the row labels change. "Open" is
    // `cash_registers.create` (POST /cash-registers/open), "Close" is
    // `cash_registers.update` (POST /cash-registers/{id}/close), and
    // "Transaction" is `cash_registers.transactions.create`, the manual cash
    // in / cash out endpoint.
    //
    // "Update" and "Delete" are the two further capabilities behind
    // PUT|PATCH and DELETE on /cash-registers/{id}/transactions/{transaction}.
    // They are genuine CRUD verbs, so unlike "Open"/"Close" they keep their
    // plain labels — and they are distinct slugs, not aliases of
    // "Transaction": recording a movement, correcting one and removing one are
    // three separate decisions an administrator can take separately.
    //
    // Both act **only** on manual cash in / cash out. The API refuses a
    // sale- or expense-generated movement whatever the grant, which is why the
    // action buttons in the drawer also test the row's own classification.
    children: [
      { label: "View", keys: ["cash_registers.view"] },
      { label: "Open", keys: ["cash_registers.create"] },
      { label: "Close", keys: ["cash_registers.update"] },
      { label: "Transaction", keys: ["cash_registers.transactions.create"] },
      { label: "Update", keys: ["cash_registers.transactions.update"] },
      { label: "Delete", keys: ["cash_registers.transactions.delete"] },
    ],
  },
  {
    id: "reports",
    label: "Reports",
    children: [{ label: "View", keys: ["reports.view"] }],
  },
  {
    id: "users",
    label: "Users",
    children: [
      { label: "View", keys: ["users.view"] },
      { label: "Create", keys: ["users.create"] },
      { label: "Update", keys: ["users.update"] },
      { label: "Roles", keys: ["users.roles.update"] },
    ],
  },
  {
    id: "roles",
    label: "Roles",
    children: [
      { label: "View", keys: ["roles.view"] },
      { label: "Create", keys: ["roles.create"] },
      { label: "Update", keys: ["roles.update"] },
      { label: "Delete", keys: ["roles.delete"] },
    ],
  },
  {
    id: "permissions",
    label: "Permissions",
    children: [{ label: "View", keys: ["permissions.view"] }],
  },
  {
    id: "settings",
    label: "Settings",
    children: [
      { label: "View", keys: ["settings.view"] },
      { label: "Update", keys: ["settings.update"] },
    ],
  },
];

/** A child row resolved against the loaded catalogue. */
export interface PermissionModuleChild {
  /** Stable key for React: the module id plus the child label. */
  id: string;
  label: string;
  /**
   * Every catalogue permission this row toggles. Usually one; a row such as
   * "Return" deliberately covers the two capabilities that make it up.
   */
  permissionIds: readonly number[];
}

/** One module resolved against the loaded catalogue. */
export interface PermissionModule {
  id: string;
  label: string;
  /** Empty for a single-capability module, which needs no child rows. */
  children: readonly PermissionModuleChild[];
  /** Every capability in the module, children included — what its checkbox toggles. */
  permissionIds: readonly number[];
}

/**
 * Groups the loaded catalogue into the POS modules.
 *
 * A spec whose slugs are all absent yields no module, so a backend that never
 * seeded `inventory.adjust` simply shows Inventory without that child rather than
 * a checkbox that could never save. The catch-all pass runs last, so nothing the
 * catalogue contains is ever dropped from the editor.
 *
 * One pass over the catalogue, so the result can be memoised by the caller.
 */
export function permissionsByModule(
  permissions: readonly Permission[],
): PermissionModule[] {
  const idByKey = new Map<PermissionKey, number>();
  for (const permission of permissions) {
    idByKey.set(permission.key, permission.id);
  }

  const claimed = new Set<PermissionKey>();
  const modules: PermissionModule[] = [];

  for (const spec of MODULE_SPECS) {
    const children: PermissionModuleChild[] = [];
    const permissionIds: number[] = [];

    for (const child of spec.children) {
      const ids = child.keys
        .filter((key) => idByKey.has(key))
        .map((key) => {
          claimed.add(key);
          return idByKey.get(key) as number;
        });

      if (ids.length === 0) {
        continue;
      }

      children.push({
        id: `${spec.id}-${child.label}`,
        label: child.label,
        permissionIds: ids,
      });
      permissionIds.push(...ids);
    }

    // A module that resolved to a single capability (Reports, Permissions) keeps
    // that one checkbox on its header: a child row would only repeat it.
    if (children.length === 1) {
      modules.push({
        id: spec.id,
        label: spec.label,
        children: [],
        permissionIds: children[0].permissionIds,
      });
      continue;
    }

    if (children.length === 0) {
      continue;
    }

    modules.push({ id: spec.id, label: spec.label, children, permissionIds });
  }

  const unclaimed = permissions.filter(
    (permission) => !claimed.has(permission.key),
  );
  if (unclaimed.length > 0) {
    modules.push({
      id: "other",
      label: OTHER_MODULE_LABEL,
      children: unclaimed.map((permission) => ({
        id: `other-${permission.key}`,
        label: permission.label,
        permissionIds: [permission.id],
      })),
      permissionIds: unclaimed.map((permission) => permission.id),
    });
  }

  return modules;
}
