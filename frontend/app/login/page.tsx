import { Suspense } from "react";
import type { Metadata } from "next";

import { LoginForm } from "@/features/auth/components/LoginForm";

export const metadata: Metadata = {
  title: "Sign in",
  description: "Sign in to open the till.",
};

/**
 * `/login` — the sign-in screen.
 *
 * A thin shell: the form and its states live in `features/auth`, so the route
 * stays a page-level composition like every other route in the app.
 *
 * `Suspense` is required because `LoginForm` reads `?next=` through
 * `useSearchParams`; without a boundary Next.js cannot prerender the route.
 */
export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}