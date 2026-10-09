import type { Metadata } from "next";

import { RootRedirect } from "@/features/auth/components/RootRedirect";

export const metadata: Metadata = {
  title: "POS",
  description: "Point of sale management system.",
};

/**
 * `/` — entry point.
 *
 * The session is a bearer token in `sessionStorage` with no server-side auth
 * middleware, so the account type is only knowable on the client after the
 * session is restored. A server-side `redirect("/pos")` would therefore drop
 * every visitor — including the Platform Owner — into the Business User
 * dashboard. `RootRedirect` resolves the authenticated account type first and
 * then sends a Platform Owner to `/platform` and a Business User to `/pos`;
 * an unauthenticated visitor continues on to `/login`.
 */
export default function Home() {
  return <RootRedirect />;
}

