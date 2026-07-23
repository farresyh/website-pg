/**
 * TODO: wire to Laravel Sanctum login endpoint (AUTH-1/AUTH-2) once it
 * exists, then set the SESSION_COOKIE_NAME cookie (see src/lib/auth.ts).
 * MFA (AUTH-7) will need a second step here before the session is granted.
 */
export default function LoginPage() {
  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <form className="w-full max-w-sm space-y-4 rounded-lg border border-black/10 p-6 dark:border-white/15">
        <h1 className="text-lg font-semibold">Sign in</h1>
        <div className="space-y-1">
          <label htmlFor="email" className="text-sm font-medium">
            Email
          </label>
          <input
            id="email"
            type="email"
            className="w-full rounded border border-black/10 px-3 py-2 text-sm dark:border-white/15"
            disabled
          />
        </div>
        <div className="space-y-1">
          <label htmlFor="password" className="text-sm font-medium">
            Password
          </label>
          <input
            id="password"
            type="password"
            className="w-full rounded border border-black/10 px-3 py-2 text-sm dark:border-white/15"
            disabled
          />
        </div>
        <button
          type="submit"
          disabled
          className="w-full rounded bg-foreground py-2 text-sm font-medium text-background disabled:opacity-50"
        >
          Sign in (not wired yet)
        </button>
      </form>
    </main>
  );
}
