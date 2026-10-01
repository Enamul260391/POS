import { create } from "zustand";

import type { UserFormValues } from "@/features/users/lib/userSchema";
import {
  createUser as createUserRequest,
  listUserRoles,
  listUsers,
  updateUser as updateUserRequest,
  type UserFilters,
} from "@/features/users/services/userService";
import type { ApiPagination } from "@/types/api";
import type { User, UserRoleOption } from "@/types/user";

/**
 * Staff directory store, hydrated from `GET /users`.
 *
 * Replaces the old `mock/users.json` seed for the `/users` screen: the database
 * is now the only source of truth and nothing here is local. The superseded
 * mock-seeded user store has been removed along with that seed file.
 *
 * The role catalogue is loaded from `GET /roles` into this same store rather
 * than from `useRoleStore`, because the picker must submit real role ids — the
 * mock catalogue used different ids, and the API validates them against the
 * caller's business.
 *
 * Pagination is entirely server-side: the store holds one page at a time and the
 * API's pagination block is the only source of the directory total.
 */

/** Page size the directory asks for; matches `UserService::DEFAULT_PER_PAGE`. */
const PER_PAGE = 20;

/**
 * Guards against a stale read overwriting a newer one.
 *
 * Page changes fire several requests in quick succession, so the same run-id
 * pattern `useCashDrawerStore` uses is applied here: a superseded run returns
 * without touching state.
 */
let loadRun = 0;

export interface UserDirectoryStore {
  /** Rows the screen is currently showing (one server page). */
  users: User[];
  /**
   * Pagination metadata of the loaded page, as reported by `GET /users`.
   *
   * `null` before the first successful load. This is the only correct source
   * for the "N of M users" total: `users.length` counts one server page, not
   * the directory, so it would silently under-report any result set larger
   * than `per_page`.
   */
  pagination: ApiPagination | null;
  /** Roles available in the picker and the role filter. */
  roles: UserRoleOption[];
  /** `true` while a list request is in flight. */
  isLoading: boolean;
  /** `true` while the role catalogue is being fetched for the first time. */
  isLoadingRoles: boolean;
  /**
   * Message of the last failure loading the *user list*, or `null`.
   *
   * Kept apart from {@link rolesError} on purpose. The list and the role
   * catalogue are independent requests gated on different capabilities
   * (`users.view` and `roles.view`), and a user can hold the first without the
   * second. Sharing one field between them meant a refused catalogue fetch
   * painted its `Forbidden` over a list that had loaded perfectly well, so the
   * screen showed the table and an error banner side by side.
   */
  usersError: string | null;
  /**
   * Message of the last failure loading the role catalogue, or `null`.
   *
   * Never shown as the screen's main error: the directory is still usable without
   * it, so this only suppresses the role filter and picker rather than
   * invalidating the rows on screen.
   */
  rolesError: string | null;
  /** `true` once at least one successful user load has completed. */
  isLoaded: boolean;

  /** Loads one page of users into `users`. */
  load: (filters?: UserFilters, page?: number) => Promise<void>;
  /** Loads the role catalogue. A no-op once it has been loaded. */
  loadRoles: () => Promise<void>;
  /** Creates a user through the API and prepends it. */
  addUser: (values: UserFormValues) => Promise<User>;
  /** Updates a user through the API and replaces the cached row. */
  updateUser: (userId: number, values: UserFormValues) => Promise<User>;
  /** Empties the cache, e.g. on sign-out. */
  reset: () => void;
}

/** Maps form values onto the API body. */
function toUserInput(values: UserFormValues) {
  return {
    name: values.name.trim(),
    email: values.email.trim(),
    phone: values.phone.trim() || null,
    branch: values.branch.trim() || null,
    status: values.status,
    notes: values.notes.trim() || null,
    roleId: values.roleId,
    password: values.password,
  };
}

/** Display-safe message of a rejection. */
function messageOf(error: unknown, fallback: string): string {
  if (
    typeof error === "object" &&
    error !== null &&
    "message" in error &&
    typeof (error as { message: unknown }).message === "string"
  ) {
    const message = (error as { message: string }).message;
    if (message.trim() !== "") {
      return message;
    }
  }
  return fallback;
}

export const useUserDirectoryStore = create<UserDirectoryStore>()((set, get) => ({
  users: [],
  pagination: null,
  roles: [],
  isLoading: false,
  isLoadingRoles: false,
  usersError: null,
  rolesError: null,
  isLoaded: false,

  async load(filters, page = 1) {
    const run = ++loadRun;
    set({ isLoading: true, usersError: null });
    try {
      const result = await listUsers({
        ...filters,
        page,
        perPage: filters?.perPage ?? PER_PAGE,
      });
      if (run !== loadRun) {
        return;
      }

      // A page past the end (staff removed elsewhere, or a filter that shrank)
      // comes back empty. Laravel still reports the real `last_page`, so the
      // last page that exists is re-requested once instead of leaving the
      // screen permanently blank.
      if (
        result.items.length === 0 &&
        result.pagination.total > 0 &&
        page > result.pagination.last_page
      ) {
        await get().load(filters, result.pagination.last_page);
        return;
      }

      set({
        users: result.items,
        pagination: result.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      if (run !== loadRun) {
        return;
      }
      set({
        isLoading: false,
        usersError: messageOf(error, "Could not load users."),
      });
    }
  },

  async loadRoles() {
    // The catalogue changes rarely, so a successful load is not repeated on
    // every screen visit; `reset()` (sign-out) clears it again.
    if (get().roles.length > 0 || get().isLoadingRoles) {
      return;
    }
    set({ isLoadingRoles: true, rolesError: null });
    try {
      const roles = await listUserRoles();
      set({ roles, isLoadingRoles: false });
    } catch (error) {
      // Recorded as `rolesError` and nothing else. A `403` here means the user
      // may read the directory but not the role catalogue, which is a perfectly
      // valid combination: the table stays exactly as loaded and only the role
      // filter and picker go away. Writing this into the shared list error was
      // what made a working directory look broken.
      set({
        isLoadingRoles: false,
        rolesError: messageOf(error, "Could not load roles."),
      });
    }
  },

  async addUser(values) {
    const created = await createUserRequest(toUserInput(values));
    set((state) => ({ users: [created, ...state.users] }));
    return created;
  },

  async updateUser(userId, values) {
    const updated = await updateUserRequest(userId, toUserInput(values));
    set((state) => ({
      users: state.users.map((user) => (user.id === userId ? updated : user)),
    }));
    return updated;
  },

  reset() {
    set({
      users: [],
      pagination: null,
      roles: [],
      isLoading: false,
      isLoadingRoles: false,
      usersError: null,
      rolesError: null,
      isLoaded: false,
    });
  },
}));

/** Selects the loaded directory; stable reference until a mutation. */
export const selectDirectoryUsers = (state: UserDirectoryStore): User[] =>
  state.users;

/**
 * Selects the API's pagination block for the loaded page; `null` before the
 * first successful load. `total` is the directory size the server counted for
 * the active filters, not the length of the current page.
 */
export const selectDirectoryPagination = (
  state: UserDirectoryStore,
): ApiPagination | null => state.pagination;

/** Selects the role catalogue; stable reference until a reload. */
export const selectDirectoryRoles = (state: UserDirectoryStore): UserRoleOption[] =>
  state.roles;