import { fileURLToPath } from "node:url";

import { defineConfig } from "vitest/config";

/**
 * Vitest configuration for the frontend.
 *
 * There was no JavaScript test runner in the project before this change; Vitest
 * was chosen because it transpiles the app's TypeScript/TSX through esbuild with
 * no extra Babel/React plugin and shares the same `@/*` path alias as
 * `tsconfig.json`. Tests live beside the code under `__tests__` folders and use
 * the `jsdom` environment for anything that renders a component.
 */
export default defineConfig({
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./", import.meta.url)),
    },
  },
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./vitest.setup.ts"],
    include: ["**/__tests__/**/*.{test,spec}.{ts,tsx}"],
    exclude: ["node_modules", ".next"],
  },
});
