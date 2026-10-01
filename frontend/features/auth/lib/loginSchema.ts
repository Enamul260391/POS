import { z } from "zod";

/** Form contract of the login screen (Phase 25). */

/**
 * Client-side validation only.
 *
 * The authoritative check is `POST /auth/login` — Laravel decides whether the
 * credentials are valid, and a `422` on a missing field is mapped back onto the
 * form by `LoginForm`. This schema exists so an obviously empty submit never
 * costs a round trip, not to pre-empt the server.
 */
export const loginSchema = z.object({
  email: z
    .string()
    .trim()
    .min(1, "Email is required")
    .email("Enter a valid email address"),
  password: z.string().min(1, "Password is required"),
});

export type LoginFormValues = z.infer<typeof loginSchema>;

/** Blank state for a fresh sign-in. */
export const EMPTY_LOGIN_VALUES: LoginFormValues = {
  email: "",
  password: "",
};

/**
 * First field a `422` refers to, so the server's own message can be shown on
 * the input that caused it.
 *
 * Laravel's error bag is keyed by field name; the first entry is the one the
 * developer needs to fix first.
 */
export function firstErrorField(
  fieldErrors: Record<string, string[]>,
): keyof LoginFormValues | null {
  for (const field of ["email", "password"] as const) {
    const messages = fieldErrors[field];
    if (messages !== undefined && messages.length > 0) {
      return field;
    }
  }
  return null;
}