/**
 * ADR-078 2026-11-08 addendum: a read that never makes a visitor wait for a
 * refresh. An expired value is served at once and replaced behind it; only an
 * empty cache, or a value past `staleLimitMs`, makes a reader wait. Readers
 * during a load share that one load, so a page's burst of requests can't turn
 * into a burst on the API.
 *
 * `load` must not reject: it decides what a failed read falls back to, and it
 * receives the value it would replace so it can keep it.
 */
interface StaleCacheOptions {
  /** A value this young is served with no load. */
  freshMs: number;
  /** A value this old is never served: the reader waits for a new one. */
  staleLimitMs: number;
  now?: () => number;
}

export interface StaleCache<T> {
  /** `background` keeps the refresh alive after the response has gone. */
  get(key: string, load: (previous?: T) => Promise<T>, background: (work: Promise<unknown>) => void): Promise<T>;
}

export function createStaleCache<T>({ freshMs, staleLimitMs, now = Date.now }: StaleCacheOptions): StaleCache<T> {
  const entries = new Map<string, { value: T; at: number }>();
  const loading = new Map<string, Promise<T>>();

  function refresh(key: string, load: (previous?: T) => Promise<T>): Promise<T> {
    const running = loading.get(key);
    if (running) return running;

    const started = load(entries.get(key)?.value)
      .then((value) => {
        entries.set(key, { value, at: now() });
        return value;
      })
      .finally(() => loading.delete(key));

    loading.set(key, started);
    return started;
  }

  return {
    async get(key, load, background) {
      const entry = entries.get(key);
      const age = entry ? now() - entry.at : Infinity;

      if (entry && age < freshMs) return entry.value;

      if (entry && age < staleLimitMs) {
        background(refresh(key, load));
        return entry.value;
      }

      return refresh(key, load);
    },
  };
}
