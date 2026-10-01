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
import { useAuthStore } from "@/stores/useAuthStore";

/** Where a cashier lands after signing in. */
const DEFAULT_REDIRECT = "/pos";

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
      await login({ email: values.email.trim(), password: values.password });
      // `AuthGate` passes the originally requested route as `?next=`; the
      // fallback is the till. Only same-origin, absolute paths are honoured, so
      // a crafted link cannot bounce the user off-site after signing in.
      const next = searchParams.get("next");
      const destination =
        next !== null && next.startsWith("/") && !next.startsWith("//")
          ? next
          : DEFAULT_REDIRECT;
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
      <section className="w-full max-w-sm rounded-xl border border-border bg-card p-8">
        <div className="flex flex-col items-center gap-2 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-primary text-primary-foreground">
            <Store className="size-6" aria-hidden="true" />
          </span>
          <h1 className="text-lg font-semibold tracking-tight">{env.appName}</h1>
          <p className="text-sm text-muted-foreground">
            Sign in to open the till.
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} noValidate className="mt-6 grid gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              autoComplete="username"
              placeholder="admin@pos.local"
              autoFocus
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
            {/* The button is a sibling of the input, not nested inside it, so the
                input keeps being a plain text field for autofill and password
                managers. The right padding on the input reserves the icon's
                space, which is the only spacing this change introduces. */}
            <div className="relative">
              <Input
                id="password"
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
                placeholder="••••••••"
                className="pr-8"
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

          <Button type="submit" size="lg" className="w-full" disabled={isLoading}>
            <LogIn aria-hidden="true" />
            {isLoading ? "Signing in…" : "Sign in"}
          </Button>
        </form>
      </section>
    </main>
  );
}