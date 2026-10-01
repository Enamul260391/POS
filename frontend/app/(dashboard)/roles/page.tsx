import type { Metadata } from "next";

import { RolesScreen } from "@/features/roles/components/RolesScreen";

export const metadata: Metadata = {
  title: "Roles",
  description: "Roles and their permission sets.",
};

/**
 * `/roles` — role management.
 *
 * The catalogue is served by the Laravel Roles / Permissions API: `/roles`
 * supplies the list and the persisted grants, `/permissions` the global
 * catalogue the matrix renders, and the "Users" column is the API's own
 * `users_count`. A reload re-reads the database, so a created, edited or deleted
 * role survives a refresh.
 */
export default function RolesPage() {
  return (
    <>
      <h1 className="sr-only">Roles</h1>
      <RolesScreen />
    </>
  );
}
