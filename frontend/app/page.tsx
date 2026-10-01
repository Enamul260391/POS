import type { Metadata } from "next";
import { redirect } from "next/navigation";

export const metadata: Metadata = {
  title: "POS",
  description: "Point of sale management system.",
};

/**
 * `/` — entry point.
 *
 * The application has no public landing page: an unauthenticated visitor is
 * sent to the login form by `AuthGate`, and an authenticated one goes straight
 * to the till. Redirecting here keeps the root from rendering a page that
 * would only ever bounce.
 */
export default function Home() {
  redirect("/pos");
}
