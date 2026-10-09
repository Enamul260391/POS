/**
 * Per-tab inactivity watchdog (idle logout).
 *
 * The POS must sign a cashier out after a fixed stretch of no real user
 * interaction, without ever letting one tab's timer meddle with another tab's
 * independent session (each browser tab runs its own JS context, so this module
 * is naturally per-tab — nothing is shared through `localStorage`).
 *
 * Design decisions that matter for correctness in a real browser:
 *
 *   - **Elapsed-time, not callback-scheduling.** The deadline is `lastActivity +
 *     timeoutMs` and a cheap interval simply *checks* it. A `setTimeout` that is
 *     expected to fire exactly on time is unreliable across laptop sleep,
 *     background-tab throttling and timer coalescing, so the timer is only a
 *     poller; `Date.now()` is the authority.
 *   - **Visibility catch-up.** When a tab is backgrounded the browser throttles
 *     timers (often to ~1/minute). Returning to a visible tab therefore runs an
 *     immediate check via the `visibilitychange` listener rather than waiting for
 *     the next throttled tick.
 *   - **Throttled activity recording.** `mousemove`/`scroll` can fire hundreds of
 *     times a second. Recording is throttled to at most once per second and only
 *     mutates a local number — no React state, no re-render — so tracking is
 *     effectively free.
 *   - **Only genuine interaction counts.** Network/API activity deliberately does
 *     *not* reset the deadline: a background poll must never keep an idle cashier
 *     signed in.
 *
 * Server-component safety: every entry point no-ops when `window` is absent.
 */

/** Inactivity window before an automatic sign-out: 180 seconds (3 minutes). */
export const IDLE_TIMEOUT_MS = 180_000;

/** How often the deadline is re-checked. Small enough to be prompt, cheap enough to ignore. */
const POLL_INTERVAL_MS = 1_000;

/** Minimum gap between two activity timestamps, throttling high-frequency events. */
const ACTIVITY_THROTTLE_MS = 1_000;

/**
 * DOM events treated as meaningful user interaction. Passive listeners so they
 * never block scrolling, and deliberately *not* including any network event.
 */
const ACTIVITY_EVENTS: readonly string[] = [
  "mousemove",
  "mousedown",
  "mouseup",
  "click",
  "keydown",
  "keyup",
  "touchstart",
  "touchend",
  "scroll",
  "wheel",
];

/**
 * Starts watching for inactivity and returns a `stop` function.
 *
 * `onIdle` is invoked at most once per watch: as soon as it fires the internal
 * deadline is pushed into the future so a late poll or a lingering event cannot
 * call it twice. The caller must call the returned `stop()` on unmount or when
 * the authenticated account changes, which clears the interval and removes every
 * listener so no stale timer can ever sign out a newly authenticated account.
 */
export function startIdleWatch(
  onIdle: () => void,
  timeoutMs: number = IDLE_TIMEOUT_MS,
): () => void {
  if (typeof window === "undefined" || typeof document === "undefined") {
    return () => undefined;
  }

  let lastActivity = Date.now();
  let lastRecorded = 0;

  const recordActivity = (): void => {
    const now = Date.now();
    if (now - lastRecorded < ACTIVITY_THROTTLE_MS) {
      return;
    }
    lastRecorded = now;
    lastActivity = now;
  };

  const checkDeadline = (): void => {
    if (Date.now() - lastActivity < timeoutMs) {
      return;
    }
    // Deadline reached: fire once and disarm, so neither another poll tick nor a
    // queued activity event can trigger a second sign-out for the same session.
    lastActivity = Date.now() + Number.MAX_SAFE_INTEGER;
    onIdle();
  };

  const onVisibilityChange = (): void => {
    if (document.visibilityState === "visible") {
      // A returning tab re-checks immediately instead of trusting a throttled poll.
      checkDeadline();
    }
  };

  for (const event of ACTIVITY_EVENTS) {
    window.addEventListener(event, recordActivity, { passive: true });
  }
  document.addEventListener("visibilitychange", onVisibilityChange);

  const intervalId = window.setInterval(checkDeadline, POLL_INTERVAL_MS);

  return () => {
    window.clearInterval(intervalId);
    for (const event of ACTIVITY_EVENTS) {
      window.removeEventListener(event, recordActivity);
    }
    document.removeEventListener("visibilitychange", onVisibilityChange);
  };
}
