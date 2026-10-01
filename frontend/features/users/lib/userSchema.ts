import { z } from "zod";

import type { User, UserRoleOption, UserStatus } from "@/types/user";

/**
 * Form contract of the Add/Edit user dialog.
 *
 * Mirrors `customerSchema`: one schema factory serves validation and typing.
 * `createUserSchema` closes email and phone uniqueness over the directory and
 * pins `roleId` to a real role, so a save can never reference a vanished role
 * or a duplicate contact.
 *
 * Since the screen is API-backed, the *real* authority on these rules is
 * Laravel (`StoreUserRequest` / `UpdateUserRequest`), and its messages are
 * mapped back onto the fields in `UserForm`. The checks kept here are the ones
 * that give instant feedback without a round trip.
 *
 * Password handling changed with the API: it is genuinely persisted now, and a
 * blank value on an edit means "keep the current one" (which is what the
 * service does), so it is no longer validated as a discarded field.
 */

/** Select options for the user status field (form and filters share them). */
export const USER_STATUS_OPTIONS: readonly { value: UserStatus; label: string }[] = [
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

/** Loose phone shape: digits, spaces and dashes with an optional leading `+`. */
const PHONE_PATTERN = /^\+?[0-9\s-]{6,20}$/;

/**
 * Digits-only, lowercased form of a phone number used for duplicate
 * comparison; `null` when the number is too short to be a real contact.
 */
function normalizePhone(phone: string | null): string | null {
  const compact = (phone ?? "").replace(/[^0-9+]/g, "");
  return compact.length >= 6 ? compact.toLowerCase() : null;
}

const userFields = {
  name: z
    .string()
    .trim()
    .min(1, "Name is required")
    .max(120, "Name is too long"),
  email: z
    .string()
    .trim()
    .email("Enter a valid email address")
    .max(254, "Email is too long"),
  phone: z
    .string()
    .trim()
    .max(32, "Phone is too long")
    .refine(
      (value) => value === "" || PHONE_PATTERN.test(value),
      "Enter a valid phone number",
    ),
  roleId: z
    .number({ invalid_type_error: "Select a role" })
    .int("Select a role")
    .min(1, "Select a role"),
  branch: z.string().trim().max(120, "Branch name is too long"),
  status: z.enum(["active", "inactive"]),
  notes: z.string().trim().max(500, "Notes are too long"),
  password: z.string(),
  confirmPassword: z.string(),
};

/** Valid shape of the user form. */
export const baseUserSchema = z.object(userFields);

export type UserFormValues = z.infer<typeof baseUserSchema>;

/**
 * Schema for one save: base fields plus the rules that need the directory —
 * email/phone uniqueness (excluding the row being edited), a `roleId` that
 * exists, and add-mode password rules. Edit mode allows an empty password so
 * the form can reset credentials without re-typing them every save.
 */
export function createUserSchema(
  existing: readonly User[],
  roles: readonly UserRoleOption[],
  editingId: number | null,
): z.ZodEffects<z.ZodObject<typeof userFields>> {
  return baseUserSchema.superRefine((values, ctx) => {
    const email = values.email.toLowerCase();
    if (
      existing.some(
        (user) => user.id !== editingId && user.email.toLowerCase() === email,
      )
    ) {
      ctx.addIssue({ code: "custom", message: "Email already exists", path: ["email"] });
    }

    const phone = normalizePhone(values.phone);
    if (phone !== null) {
      if (
        existing.some(
          (user) =>
            user.id !== editingId && normalizePhone(user.phone) === phone,
        )
      ) {
        ctx.addIssue({
          code: "custom",
          message: "Phone number already exists",
          path: ["phone"],
        });
      }
    }

    if (!roles.some((role) => role.id === values.roleId)) {
      ctx.addIssue({ code: "custom", message: "Select a valid role", path: ["roleId"] });
    }

    // A new account must get a usable password; on an edit a blank value means
    // "keep the current one", which the service honours. Both are checked
    // again by Laravel, so this is instant feedback rather than the only guard.
    if (editingId === null) {
      if (values.password.length < 8) {
        ctx.addIssue({
          code: "custom",
          message: "Password is required (minimum 8 characters)",
          path: ["password"],
        });
      }
    } else if (values.password !== "" && values.password.length < 8) {
      ctx.addIssue({
        code: "custom",
        message: "Must be at least 8 characters",
        path: ["password"],
      });
    }

    if (values.password !== values.confirmPassword) {
      ctx.addIssue({
        code: "custom",
        message: "Passwords do not match",
        path: ["confirmPassword"],
      });
    }
  });
}

/** Blank state for "Add user". */
export const EMPTY_USER_VALUES: UserFormValues = {
  name: "",
  email: "",
  phone: "",
  roleId: 0,
  branch: "",
  status: "active",
  notes: "",
  password: "",
  confirmPassword: "",
};

/** Maps an existing user into form state (edit mode). Passwords stay blank. */
export function toUserFormValues(user: User): UserFormValues {
  return {
    name: user.name,
    email: user.email,
    phone: user.phone ?? "",
    roleId: user.roleId,
    branch: user.branch ?? "",
    status: user.status,
    notes: user.notes ?? "",
    password: "",
    confirmPassword: "",
  };
}
