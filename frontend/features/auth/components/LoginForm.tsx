"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { Eye, EyeOff, LogIn, Store } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  EMPTY_LOGIN_VALUES,
  firstErrorField,
  loginSchema,
  type LoginFormValues,
} from "@/features/auth/lib/loginSchema";
import { env } from "@/lib/env";
import { readWorkState, restoreWorkState } from "@/lib/pos/workState";
import {
  BUSINESS_LANDING_ROUTE,
  PLATFORM_LANDING_ROUTE,
  businessRedirectTarget,
  platformRedirectTarget,
} from "@/features/auth/lib/routing";
import { useAuthStore } from "@/stores/useAuthStore";

/** Inline validation or server message rendered under a field. */
function FormError({ message }: { message?: string }) {
  if (message === undefined) {
    return null;
  }
  return (
    <p className="text-xs text-destructive" role="alert">
      {message}
    </p>
  );
}

/**
 * `/login` — the sign-in screen.
 *
 * Preserves the visual language of the rest of the application (same tokens,
 * same card, same button) and adds only what authentication needs:
 *
 *   - a form that calls `POST /auth/login` through the auth store
 *   - loading, invalid-credential, validation, network and server errors
 *   - a redirect to the POS once a session exists
 *
 * The screen is intentionally dumb: it owns the two inputs and the messages,
 * while `useAuthStore` owns the session and the redirect target is the only
 * routing decision made here.
 */
export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const login = useAuthStore((state) => state.login);
  const isLoading = useAuthStore((state) => state.isLoading);
  const error = useAuthStore((state) => state.error);
  const fieldErrors = useAuthStore((state) => state.fieldErrors);
  const clearError = useAuthStore((state) => state.clearError);

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: EMPTY_LOGIN_VALUES,
  });
  const { register, handleSubmit, setError, formState } = form;
  const errors = formState.errors;

  // Whether the password is currently revealed. Local presentation state only:
  // it never reaches the form values, the auth store or the API — the value
  // submitted is identical either way. Reset to hidden on a failed attempt so a
  // mistyped password never stays legible to the next person at the till.
  const [showPassword, setShowPassword] = useState(false);

  // A `422` from the login endpoint names the offending field; surfacing it on
  // the input is more useful than showing it as a generic banner only.
  useEffect(() => {
    const field = firstErrorField(fieldErrors);
    if (field === null) {
      return;
    }
    const messages = fieldErrors[field];
    setError(field, { message: messages?.[0] });
  }, [fieldErrors, setError]);

  async function onSubmit(values: LoginFormValues) {
    try {
      const user = await login({ email: values.email.trim(), password: values.password });

      // Recover this principal's saved work state (cart + last working page) from
      // an idle sign-out. The record is only read back for the exact user+business
      // that just signed in, so no other account's or tenant's cart can leak in.
      // The cart is revalidated against the current catalogue on restore.
      const workState = readWorkState(user.id, user.businessId);
      if (workState !== null) {
        restoreWorkState(workState);
      }

      // `AuthGate` passes the originally requested route as `?next=`, and an idle
      // sign-out saved the last working page. `?next=` and the saved route are both
      // attacker/stale-controllable, so only a same-origin, single-slash internal
      // path is ever honoured, and only when it belongs to the side of the app the
      // principal may be on. `user.isPlatformOwner` is the trusted server-resolved
      // identity, so the redirect honours it. The safe-path rules live in
      // `@/features/auth/lib/routing`, shared with the root entry redirect.
      //
      // Precedence: a saved working route (validated) wins over `?next=`, so the
      // cashier resumes exactly where they left off; a saved route that is no
      // longer valid/authorized is discarded and the normal landing applies.
      const savedRoute = workState?.route ?? null;
      const requested = savedRoute ?? searchParams.get("next");
      const destination = user.isPlatformOwner
        ? (platformRedirectTarget(requested) ?? PLATFORM_LANDING_ROUTE)
        : (businessRedirectTarget(requested) ?? BUSINESS_LANDING_ROUTE);
      // `replace` rather than `push`: the login route must not stay in history,
      // otherwise Back would return to a page the user may no longer see.
      router.replace(destination);
      router.refresh();
    } catch {
      // The store has already placed the message on the store (`error`) and,
      // for a 422, on the field. Clearing the password forces a fresh attempt
      // without making the cashier retype the email. It is also re-masked, so
      // a rejected password never stays readable on a shared till.
      form.setValue("password", "");
      setShowPassword(false);
    }
  }

  return (
    <main className="flex flex-1 items-center justify-center bg-background p-6">
      <div className="w-full max-w-sm space-y-6 rounded-2xl border-2 border-primary/30 bg-card p-8 shadow-md -mt-[50px]">
        <div className="flex flex-col items-center gap-2 text-center">
          <span className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Store className="size-5" aria-hidden="true" />
          </span>
          <h1 className="text-lg font-semibold tracking-tight">{env.appName}</h1>
          <p className="text-sm text-muted-foreground">Sign in to open the till.</p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="email">Email</Label>
              <Input
                id="email"
                type="email"
                autoComplete="username"
                placeholder="admin@pos.local"
                autoFocus
                className="rounded-md border-2 border-violet-500/60"
                aria-invalid={errors.email !== undefined}
                {...register("email", {
                  onChange: () => {
                    if (error !== null) {
                      clearError();
                    }
                  },
                })}
              />
              <FormError message={errors.email?.message} />
            </div>

            <div className="grid gap-1.5">
              <Label htmlFor="password">Password</Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  autoComplete="current-password"
                  placeholder="••••••••"
                  className="pr-8 rounded-md border-2 border-amber-500/60"
                  aria-invalid={errors.password !== undefined}
                  {...register("password")}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  onClick={() => setShowPassword((visible) => !visible)}
                  aria-label={showPassword ? "Hide password" : "Show password"}
                  aria-pressed={showPassword}
                  title={showPassword ? "Hide password" : "Show password"}
                  className="absolute top-1/2 right-0.5 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  {showPassword ? (
                    <EyeOff aria-hidden="true" />
                  ) : (
                    <Eye aria-hidden="true" />
                  )}
                </Button>
              </div>
              <FormError message={errors.password?.message} />
            </div>

            {error !== null && (
              <p
                className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
                role="alert"
              >
                {error}
              </p>
            )}

            <Button type="submit" size="lg" className="w-full rounded-xl" disabled={isLoading}>
              <LogIn aria-hidden="true" />
              {isLoading ? "Signing in…" : "Sign in"}
            </Button>
          </form>
        </div>
    </main>
  );
}