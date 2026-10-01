import Echo from "laravel-echo";
import Pusher from "pusher-js";

import { getToken } from "@/lib/auth/token";
import { env, realtimeAuthEndpoint } from "@/lib/env";

/**
 * The single Echo connection (Phase RT-1).
 *
 * There is exactly one Echo instance for the whole application, created lazily on
 * the first authenticated subscribe. Two instances would mean two sockets, two
 * sets of listeners and two reconnect loops for one user — the classic source of
 * "the handler fired twice" bugs — so nothing else in the codebase may construct
 * an `Echo` or a `Pusher`.
 *
 * Responsibilities, and deliberately nothing else:
 *
 *  - open **one** authenticated connection to Reverb;
 *  - subscribe to the signed-in user's own business channel;
 *  - hand the caller a channel handle to bind listeners to;
 *  - tear everything down on logout.
 *
 * It knows nothing about products or stock. Those rules live in
 * `@/stores/useProductStore`, which is the single place that decides what an
 * event means; this module only moves bytes.
 *
 * **Realtime is an enhancement, never a dependency.** Every failure path here —
 * no app key, Reverb not running, DNS failure, a rejected channel — resolves
 * quietly to "no live updates". Nothing here throws, and no caller awaits the
 * socket, so a missing WebSocket can never break rendering, block a REST call or
 * trap a screen in a loading state. The REST API stays authoritative.
 */

/**
 * The private channel shape this module subscribes to.
 *
 * laravel-echo 2.5 is generic over its broadcaster and ships a first-class
 * `"reverb"` key, so the type parameter is pinned to `"reverb"` rather than
 * left to inference. That keeps the channel handle fully typed instead of
 * collapsing to `any` (the package ships no exported names — `export {}`).
 */
export type RealtimeEcho = Echo<"reverb">;

/** The private channel handle returned by {@link subscribeToBusiness}. */
export type RealtimeChannel = ReturnType<RealtimeEcho["private"]>;

/** Event names the backend broadcasts (see each event's `broadcastAs()`). */
export const REALTIME_EVENTS = {
  productCreated: "product.created",
  productUpdated: "product.updated",
  productDeleted: "product.deleted",
  productStockUpdated: "product.stock.updated",
} as const;

/**
 * The one shared Echo instance, or `null` while realtime is unavailable.
 *
 * Module-level rather than per-component so a remount (React Strict Mode
 * double-invokes effects in development) reuses the same socket instead of
 * opening a second one.
 */
let echo: RealtimeEcho | null = null;

/** The channel currently subscribed, so `stop()` can leave it exactly once. */
let activeChannel: RealtimeChannel | null = null;

/** The business id the active subscription belongs to. */
let activeBusinessId: number | null = null;

/** Listeners bound through `bind()`, kept so `stop()` can unbind them. */
const boundEvents = new Set<string>();

/**
 * Returns the shared Echo instance, creating it on first use.
 *
 * Returns `null` when realtime is not configured (`NEXT_PUBLIC_REVERB_APP_KEY`
 * absent) or when the browser has no WebSocket support. Callers treat that as
 * "live updates are off" — exactly how the application behaved before this phase.
 */
function getEcho(): RealtimeEcho | null {
  if (echo !== null) {
    return echo;
  }

  if (!env.isRealtimeEnabled || env.reverbAppKey === null) {
    return null;
  }

  if (typeof window === "undefined" || typeof WebSocket === "undefined") {
    // Server-side render, or an environment without WebSockets: stay inert.
    return null;
  }

  try {
    // Reverb speaks the Pusher protocol, so the Pusher transport is the
    // supported client. It is passed as an option rather than assigned to
    // `window.Pusher`, which keeps the global untouched. `forceTLS` comes from
    // the configured scheme rather than being guessed, so a production `wss`
    // deployment is never silently downgraded to `ws`.
    echo = new Echo<"reverb">({
      broadcaster: "reverb",
      key: env.reverbAppKey,
      wsHost: env.reverbHost,
      wsPort: env.reverbPort,
      wssPort: env.reverbPort,
      forceTLS: env.reverbScheme === "https",
      enabledTransports: ["ws", "wss"],
      // Reverb POSTs here to have the API sign a `private-*` subscription. It
      // defaults to `/broadcasting/auth`, which this API does not serve (it is
      // bearer-authenticated and versioned), so the real endpoint is explicit.
      authEndpoint: realtimeAuthEndpoint,
      // The bearer token is attached from the single place it is stored, rather
      // than being placed in a query string — a token in a URL leaks into server
      // logs and browser history.
      auth: {
        headers: {
          Authorization: `Bearer ${getToken() ?? ""}`,
          Accept: "application/json",
        },
      },
      Pusher,
    });

    return echo;
  } catch {
    // A malformed configuration must not take the app down.
    echo = null;
    return null;
  }
}

/**
 * Subscribes to a business' private channel.
 *
 * The channel name is built from `businessId`, which callers must take from the
 * authenticated user — the browser may request any name it likes, and the API
 * refuses the ones that do not belong to the caller (see `routes/channels.php`).
 *
 * Idempotent by design: subscribing to the business that is already subscribed
 * returns the existing channel, so a second call (a remount, a re-render, a
 * restored session) cannot add a duplicate listener set.
 */
export function subscribeToBusiness(businessId: number): RealtimeChannel | null {
  const instance = getEcho();

  if (instance === null) {
    return null;
  }

  if (activeChannel !== null && activeBusinessId === businessId) {
    return activeChannel;
  }

  // Switching business (a different account in the same tab) must not leave the
  // previous tenant's subscription behind.
  if (activeChannel !== null) {
    stop();
  }

  try {
    activeChannel = instance.private(`business.${businessId}`);
    activeBusinessId = businessId;
  } catch {
    // A refused or unreachable subscription is not an error the UI must show;
    // the user simply does not get live updates.
    activeChannel = null;
    activeBusinessId = null;
  }

  return activeChannel;
}

/**
 * Binds a listener to a realtime event on the active channel.
 *
 * The binding is remembered so {@link stop} can remove it. Returns an
 * unsubscribe function, which is what a React effect cleanup should call, so a
 * component can never leave a handler behind.
 */
export function bind(event: string, handler: (payload: unknown) => void): () => void {
  if (activeChannel === null) {
    return () => undefined;
  }

  // Echo's leading dot means "client-side event", which is what Laravel's
  // `broadcastAs()` names are. Without it Echo would namespace the name itself.
  activeChannel.listen(`.${event}`, handler);
  boundEvents.add(event);

  return () => {
    // The callback is passed too, so unbinding removes exactly this handler and
    // not every handler bound to the same event name.
    activeChannel?.stopListening(`.${event}`, handler);
    boundEvents.delete(event);
  };
}

/**
 * Ends the realtime session: unbinds listeners, leaves the channel, disconnects.
 *
 * Called on logout and whenever the authenticated business goes away. Safe to
 * call repeatedly and safe to call when realtime was never started.
 */
export function stop(): void {
  if (activeChannel !== null) {
    // Unbind everything this module bound, so a later reconnect cannot inherit
    // handlers from a previous session.
    for (const event of boundEvents) {
      activeChannel.stopListening(`.${event}`);
    }

    // Leave the server-side subscription too; without this Reverb keeps the
    // member in the channel's member list until it times out.
    echo?.leave(`private-business.${activeBusinessId ?? ""}`);
  }

  boundEvents.clear();
  activeChannel = null;
  activeBusinessId = null;

  if (echo !== null) {
    try {
      echo.disconnect();
    } catch {
      // Nothing to do: the socket is being abandoned regardless.
    }
  }
}

/**
 * Registers a one-shot callback for the next successful (re)connection.
 *
 * Used for the reconnect path: a socket that dropped may have missed frames, so
 * the caller performs a single authoritative REST refresh once the connection is
 * back. This is event-driven, not polling — it fires once per real reconnect and
 * never on a timer.
 *
 * Returns an unsubscribe function.
 */
export function onReconnected(handler: () => void): () => void {
  const instance = getEcho();

  if (instance === null) {
    return () => undefined;
  }

  const connector = instance.connector;

  if (connector === undefined || typeof connector.onConnectionChange !== "function") {
    return () => undefined;
  }

  // Echo reports the connection status rather than exposing the raw Pusher
  // socket, so a reconnect is a transition back to "connected". The callback
  // only fires on that transition, so the refresh happens once per genuine
  // reconnect rather than on a timer or a poll.
  return connector.onConnectionChange((status) => {
    if (status === "connected") {
      handler();
    }
  });
}

/** `true` when a business channel is currently subscribed. */
export function isSubscribed(): boolean {
  return activeChannel !== null;
}
