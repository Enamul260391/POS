import { create } from "zustand";

import type { RoleFormValues } from "@/features/roles/lib/roleSchema";
import {
  createRole as createRoleRequest,
  deleteRole as deleteRoleRequest,
  listPermissions,
  listRoles,
  updateRole as updateRoleRequest,
  type RoleFilters,
} from "@/features/roles/services/roleService";
import type { ApiPagination } from "@/types/api";
import type { Permission } from "@/types/permission";
import type { Role } from "@/types/role";

/**
 * Role catalogue store, hydrated from `GET /roles` and `GET /permissions`.
 *
 * The database is the only source of truth: nothing here is seeded, and a
 * reload re-reads the API rather than restoring local state. That is what makes
 * a created, edited or deleted role survive a refresh.
 *
 * It owns two collections plus the loading and error state the screen needs,
 * mirroring `useUserDirectoryStore` rather than inventing a third shape.
 *
 * `permissions` is the global catalogue, fetched once and reused by the role
 * form's matrix, so a permission id submitted on save is always one the API
 * actually issued. It is re-read after a save so a catalogue that gained a row
 * server-side is picked up without a manual refresh.
 */
export interface RoleStore {
  /** Roles on the current server page. */
  roles: Role[];
  /** The global permission catalogue backing the matrix. */
  permissions: Permission[];
  /** Pagination of the current role page. */
  pagination: ApiPagination | null;
  /** `true` while a role list request is in flight. */
  isLoading: boolean;
  /** `true` while the permission catalogue is being fetched. */
  isLoadingPermissions: boolean;
  /** Display-safe message of the last failure, or `null`. */
  error: string | null;
  /** `true` once at least one successful role load has completed. */
  isLoaded: boolean;

  /** Loads one page of roles, replacing whatever is on screen. */
  load: (filters?: RoleFilters, page?: number) => Promise<void>;
  /** Loads the permission catalogue. A no-op once it has been loaded. */
  loadPermissions: (force?: boolean) => Promise<void>;
  /** Creates a role through the API and prepends it to the current page. */
  addRole: (values: RoleFormValues) => Promise<Role>;
  /** Updates a role through the API and replaces the cached row. */
  updateRole: (roleId: number, values: RoleFormValues) => Promise<Role>;
  /** Deletes a role through the API and drops it from the cached page. */
  removeRole: (roleId: number) => Promise<void>;
  /** Empties the cache, e.g. on sign-out. */
  reset: () => void;
}

/** Page size the screen requests; the API caps a page at 100. */
const PER_PAGE = 20;

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

/** Maps form values onto the API body. */
function toRoleInput(values: RoleFormValues) {
  return {
    name: values.name,
    description: values.description,
    permissionIds: values.permissionIds,
  };
}

export const useRoleStore = create<RoleStore>()((set, get) => ({
  roles: [],
  permissions: [],
  pagination: null,
  isLoading: false,
  isLoadingPermissions: false,
  error: null,
  isLoaded: false,

  async load(filters, page = 1) {
    set({ isLoading: true, error: null });
    try {
      const result = await listRoles({
        ...filters,
        page,
        perPage: filters?.perPage ?? PER_PAGE,
      });
      set({
        roles: result.items,
        pagination: result.pagination,
        isLoading: false,
        isLoaded: true,
      });
    } catch (error) {
      set({
        isLoading: false,
        error: messageOf(error, "Could not load roles."),
      });
    }
  },

  async loadPermissions(force = false) {
    // The catalogue is global and seeded, so a successful load is not repeated
    // on every screen visit. A save forces a re-read because a reseed may have
    // added rows the matrix would otherwise be unable to grant.
    if ((!force && get().permissions.length > 0) || get().isLoadingPermissions) {
      return;
    }
    set({ isLoadingPermissions: true });
    try {
      const permissions = await listPermissions();
      set({ permissions, isLoadingPermissions: false });
    } catch (error) {
      set({
        isLoadingPermissions: false,
        error: messageOf(error, "Could not load permissions."),
      });
    }
  },

  async addRole(values) {
    const created = await createRoleRequest(toRoleInput(values));
    set((state) => ({ roles: [created, ...state.roles] }));
    return created;
  },

  async updateRole(roleId, values) {
    const updated = await updateRoleRequest(roleId, toRoleInput(values));
    set((state) => ({
      roles: state.roles.map((role) => (role.id === roleId ? updated : role)),
    }));
    return updated;
  },

  async removeRole(roleId) {
    await deleteRoleRequest(roleId);
    set((state) => ({ roles: state.roles.filter((role) => role.id !== roleId) }));
  },

  reset() {
    set({
      roles: [],
      permissions: [],
      pagination: null,
      isLoading: false,
      isLoadingPermissions: false,
      error: null,
      isLoaded: false,
    });
  },
}));

/** Selects the loaded roles; stable reference until a reload. */
export const selectRoles = (state: RoleStore): Role[] => state.roles;

/** Selects the permission catalogue; stable reference until a reload. */
export const selectPermissions = (state: RoleStore): Permission[] =>
  state.permissions;
