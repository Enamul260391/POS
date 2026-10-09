import "@testing-library/react";
import { afterEach, vi } from "vitest";
import { cleanup } from "@testing-library/react";

/**
 * Shared test setup.
 *
 * `cleanup` unmounts every rendered component between tests so state from one
 * test can never leak into the next. The `matchMedia` stub is required because
 * the UI primitives (dropdown, sheet) query it on mount and jsdom does not
 * implement it.
 */
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

if (typeof window !== "undefined" && !window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia;
}
