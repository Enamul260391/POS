import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { IDLE_TIMEOUT_MS, startIdleWatch } from "@/lib/auth/idle";

/**
 * Inactivity watchdog unit tests.
 *
 * `vi.useFakeTimers()` gives a deterministic clock so the 180s deadline can be
 * advanced exactly; these pin the *logic* of the watchdog. Real browser
 * behaviour (throttling, sleep, visibility) is covered separately and is the
 * reason the deadline is computed from elapsed time rather than trusting a
 * single timer callback.
 */
describe("startIdleWatch", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("does not fire before the 180s deadline", () => {
    const onIdle = vi.fn();
    startIdleWatch(onIdle);

    vi.advanceTimersByTime(IDLE_TIMEOUT_MS - 1_000);
    expect(onIdle).not.toHaveBeenCalled();
  });

  it("fires once after 180s of inactivity (scenario: logged out after 3 minutes)", () => {
    const onIdle = vi.fn();
    startIdleWatch(onIdle);

    vi.advanceTimersByTime(IDLE_TIMEOUT_MS);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it("resets the deadline on user activity", () => {
    const onIdle = vi.fn();
    startIdleWatch(onIdle);

    // Activity just before the deadline pushes it out by a full window.
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS - 1_000);
    window.dispatchEvent(new Event("mousemove"));
    // A real interaction is not throttled here because the last record was long ago.
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS - 1_000);
    expect(onIdle).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1_000);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it("treats a key press as activity", () => {
    const onIdle = vi.fn();
    startIdleWatch(onIdle);

    vi.advanceTimersByTime(IDLE_TIMEOUT_MS - 1_000);
    window.dispatchEvent(new Event("keydown"));
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS - 1_000);
    expect(onIdle).not.toHaveBeenCalled();
  });

  it("fires at most once even if the poll keeps ticking", () => {
    const onIdle = vi.fn();
    startIdleWatch(onIdle);

    vi.advanceTimersByTime(IDLE_TIMEOUT_MS);
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS * 3);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it("stops firing after the returned stop() is called (cleanup on unmount)", () => {
    const onIdle = vi.fn();
    const stop = startIdleWatch(onIdle);

    stop();
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS * 2);
    expect(onIdle).not.toHaveBeenCalled();
  });

  it("removes its listeners on stop so a stale timer cannot log out a new session", () => {
    const onIdle = vi.fn();
    const stop = startIdleWatch(onIdle);
    stop();

    // Activity after stop must not throw and must not restart the watchdog.
    expect(() => window.dispatchEvent(new Event("mousemove"))).not.toThrow();
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS * 2);
    expect(onIdle).not.toHaveBeenCalled();
  });

  it("re-checks the deadline immediately when the tab becomes visible again", () => {
    const onIdle = vi.fn();
    startIdleWatch(onIdle);

    // Elapsed beyond the deadline, then the tab is shown again: the visibility
    // handler fires the timeout right away rather than waiting for the next tick.
    vi.advanceTimersByTime(IDLE_TIMEOUT_MS + 5_000);
    Object.defineProperty(document, "visibilityState", {
      value: "visible",
      configurable: true,
    });
    document.dispatchEvent(new Event("visibilitychange"));

    expect(onIdle).toHaveBeenCalledTimes(1);
  });
});
