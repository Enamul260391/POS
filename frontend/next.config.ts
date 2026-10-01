import type { NextConfig } from "next";

/**
 * Refuses to produce a production bundle that points at a development API.
 *
 * `NEXT_PUBLIC_*` values are inlined into the browser bundle at build time, so
 * a wrong value cannot be corrected after the fact — it has to be rebuilt. The
 * failure is also invisible from the server: the browser silently calls the
 * wrong host and the operator only sees a network/CORS error in devtools while
 * Laravel reports nothing at all.
 *
 * The realistic way this goes wrong is a leftover `.env.local` on the build
 * machine. Next.js loads `.env.local` during `next build` and gives it higher
 * priority than `.env.production`, so a developer's
 * `http://localhost:8000/api/v1` silently wins over the real production URL.
 * Failing the build here turns that silent outage into an immediate, obvious
 * error naming the variable at fault.
 *
 * The trigger distinguishes `next build` from `next dev` without trusting a
 * stray `NODE_ENV` exported by the shell. Next.js sets NODE_ENV="production"
 * for `next build` and "development" for `next dev`, but a value inherited from
 * a parent process survives that assignment and would make `next dev` look like
 * a production build — breaking local development outright.
 *
 * `NEXT_PHASE` describes the command precisely, but Next 16 no longer exports it
 * while `next.config.ts` is being evaluated (it is undefined there), so it is
 * only consulted as an explicit override when present. The reliable signal is
 * therefore the default `NODE_ENV`, combined with the phase when available.
 */
function isProductionBuild(): boolean {
  const phase = process.env.NEXT_PHASE;

  if (phase !== undefined && phase !== "") {
    return phase === "phase-production-build";
  }

  // No phase information: fall back to NODE_ENV, which is correct whenever the
  // environment was not tampered with.
  return process.env.NODE_ENV === "production";
}

function assertProductionApiUrl(): void {
  if (!isProductionBuild()) {
    return;
  }

  // An explicit opt-out for the rare case of building a production bundle that
  // genuinely should target a local API (e.g. a staging smoke test on a laptop).
  // Off by default so the safe behaviour needs no argument from anyone.
  if (process.env.NEXT_PUBLIC_ALLOW_LOCAL_API === "1") {
    return;
  }

  const configured = (
    process.env.NEXT_PUBLIC_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL ?? ""
  ).trim();

  let url: URL;
  try {
    url = new URL(configured);
  } catch {
    throw new Error(
      "NEXT_PUBLIC_API_BASE_URL is not set to a valid absolute URL.\n" +
        "It is inlined into the browser bundle at build time, so an empty value " +
        "would ship an app that cannot reach the API.\n" +
        "Set it to the production API origin, e.g. " +
        "https://api.example.com/api/v1",
    );
  }

  const loopbackHosts = ["localhost", "127.0.0.1", "::1", "0.0.0.0"];
  const host = url.hostname.toLowerCase();

  if (loopbackHosts.includes(host) || host.endsWith(".localhost")) {
    throw new Error(
      `Refusing to build for production: NEXT_PUBLIC_API_BASE_URL is "${configured}", ` +
        "which points at a local machine.\n" +
        "This is almost always a leftover .env.local shadowing .env.production — " +
        "Next.js loads .env.local during `next build` and it outranks .env.production.\n" +
        "Fix: move development values into .env.development.local (loaded only when " +
        "NODE_ENV=development), and keep the production URL in .env.production or in " +
        "real environment variables.\n" +
        "To deliberately build against a local API, set " +
        "NEXT_PUBLIC_ALLOW_LOCAL_API=1.",
    );
  }

  if (url.protocol !== "https:") {
    throw new Error(
      `Refusing to build for production: NEXT_PUBLIC_API_BASE_URL is "${configured}", ` +
        "which is not an https:// URL.\n" +
        "An https page cannot call an http API: the browser blocks it as mixed " +
        "content before CORS is even evaluated, and the failure looks like a " +
        "network error rather than a configuration mistake.",
    );
  }
}

assertProductionApiUrl();

const nextConfig: NextConfig = {
  /* config options here */

  reactCompiler: true,
};

export default nextConfig;
