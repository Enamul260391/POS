import type { Metadata } from "next";

import { UsersScreen } from "@/features/users/components/UsersScreen";

export const metadata: Metadata = {
  title: "Users",
  description: "Staff accounts, roles and status.",
};

/**
 * `/users` — user management (Phase 13).
 *
 * Deliberately tiny: filter state, table and add/edit dialog live in the users
 * feature; the directory is `useUserDirectoryStore` (hydrated from `GET /users`)
 * and the role picker reads the catalogue from `GET /roles`. Both are the
 * Laravel API — the mock user seed that once backed this page has been removed.
 */
export default function UsersPage() {
  return (
    <>
      <h1 className="sr-only">Users</h1>
      <UsersScreen />
    </>
  );
}
