import { redirect } from "next/navigation";

/**
 * `/platform` — redirect to dashboard.
 */
export default function PlatformRootPage() {
  redirect("/platform/dashboard");
}