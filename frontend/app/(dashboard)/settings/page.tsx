import type { Metadata } from "next";

import { SettingsScreen } from "@/features/settings/components/SettingsScreen";

export const metadata: Metadata = {
  title: "Settings",
  description: "Business settings stored in the API.",
};

/**
 * `/settings` — business settings.
 *
 * A thin route shell: the page is a Server Component that owns `metadata`, while
 * loading, editing and saving live in `SettingsScreen`. The sidebar link for
 * "Settings → `/settings`" resolves here.
 *
 * The values come from the Laravel settings API (`GET`/`PATCH /settings`) and are
 * scoped to the signed-in user's own business, which the backend resolves from the
 * session token — the page sends no business identifier of its own. A reload
 * re-reads the database, so a saved setting survives a refresh.
 */
export default function SettingsPage() {
  return (
    <>
      <h1 className="sr-only">Settings</h1>
      <SettingsScreen />
    </>
  );
}
