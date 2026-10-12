"use client";

import { useEffect, useState } from "react";

/**
 * Returns `value` after it has stopped changing for `delayMs`. Bind the input
 * to the raw state and every fetch (and the pagination reset) to this value,
 * so a search box costs one request per pause instead of one per keystroke.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);

  return debounced;
}
