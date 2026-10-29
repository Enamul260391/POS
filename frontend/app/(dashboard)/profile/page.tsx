import type { Metadata } from "next";

import { ProfileScreen } from "./ProfileScreen";

export const metadata: Metadata = {
  title: "Profile",
  description: "Your account details.",
};

/**
 * `/profile` — the signed-in user's own account.
 *
 * Lives inside the dashboard route group so it inherits the dashboard shell
 * (Sidebar, Topbar and AuthGate) instead of rendering as a bare 404. `metadata`
 * is server-rendered here; the session-gated screen is the client component below.
 */
export default function ProfilePage() {
  return <ProfileScreen />;
}
