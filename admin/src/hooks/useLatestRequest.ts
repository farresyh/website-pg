"use client";

import { useCallback, useRef } from "react";

/**
 * Stale-response guard for a list that several code paths (filter effect,
 * manual refresh, post-mutation refresh, push listener) all reload. Call the
 * returned `begin()` right before each fetch and check the `isLatest()` it
 * hands back before writing the result; a slower, older response then never
 * lands on top of a newer one ("PU" arriving after "PUBG"). Share one guard
 * per list, not per call site, so every path invalidates the others.
 */
export function useLatestRequest() {
  const latest = useRef(0);
  return useCallback(() => {
    const mine = ++latest.current;
    return () => mine === latest.current;
  }, []);
}
