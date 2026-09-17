"use client";

import { useEffect, useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { listGames, listGamePackages, type Game, type GamePackage } from "@/lib/games";
import type { CreateSandboxOrderValues } from "@/lib/sandboxOrders";

interface CreateSandboxOrderModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: CreateSandboxOrderValues) => Promise<void>;
  token: string;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ADR-018 decision #3: admin picks a real Game/Package from our own
 * catalog (never raw supplier fields typed by hand, unlike the legacy
 * reference this ADR was prompted by) so PricingService computes real,
 * consistent money figures. Player/customer fields all default to
 * fixed sandbox placeholders server-side when left blank.
 */
function CreateSandboxOrderFields({ onClose, onSubmit, token }: Omit<CreateSandboxOrderModalProps, "isOpen">) {
  const [games, setGames] = useState<Game[] | null>(null);
  const [gamesError, setGamesError] = useState<string | null>(null);
  const [gameId, setGameId] = useState<number | null>(null);

  const [packages, setPackages] = useState<GamePackage[] | null>(null);
  const [packageId, setPackageId] = useState<number | null>(null);

  // Adjusted during render (React's own pattern for "reset state when a
  // prop/other state changes"), not in the effect below — this way the
  // effect only performs the actual async fetch, no synchronous setState.
  const [packagesGameId, setPackagesGameId] = useState<number | null>(gameId);
  if (gameId !== packagesGameId) {
    setPackagesGameId(gameId);
    setPackageId(null);
    setPackages(null);
  }

  const [playerId, setPlayerId] = useState("");
  const [serverId, setServerId] = useState("");
  const [customerEmail, setCustomerEmail] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    listGames(token, { status: "active" })
      .then(setGames)
      .catch(() => setGamesError("Could not load games."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!gameId) return;
    listGamePackages(token, gameId).then((all) => setPackages(all.filter((p) => p.is_active)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gameId]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (!gameId || !packageId) {
      setError("Select a game and a package.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        game_id: gameId,
        package_id: packageId,
        player_id: playerId.trim() || undefined,
        server_id: serverId.trim() || undefined,
        customer_email: customerEmail.trim() || undefined,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Creates a real Order row, marked as sandbox-only (is_test), paid immediately with no payment-gateway call, and starting
        already at a &quot;failed delivery&quot; state so it&apos;s instantly usable with Resend Delivery below.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}
      {gamesError && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {gamesError}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="sandbox_game">Game</Label>
          {games === null ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading games…</p>
          ) : (
            <SimpleSelect
              id="sandbox_game"
              value={gameId !== null ? String(gameId) : ""}
              onChange={(value) => setGameId(value ? Number(value) : null)}
              options={[{ value: "", label: "Select a game…" }, ...games.map((g) => ({ value: String(g.id), label: g.name }))]}
            />
          )}
        </div>

        <div>
          <Label htmlFor="sandbox_package">Package</Label>
          {!gameId ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">Select a game first.</p>
          ) : packages === null ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading packages…</p>
          ) : (
            <SimpleSelect
              id="sandbox_package"
              value={packageId !== null ? String(packageId) : ""}
              onChange={(value) => setPackageId(value ? Number(value) : null)}
              options={[
                { value: "", label: "Select a package…" },
                ...packages.map((p) => ({ value: String(p.id), label: `${p.name} — ${formatRm(p.cost_price)}` })),
              ]}
            />
          )}
        </div>

        <div>
          <Label htmlFor="sandbox_player_id">Player ID (Optional)</Label>
          <Input id="sandbox_player_id" placeholder="Defaults to a fixed sandbox placeholder" value={playerId} onChange={(e) => setPlayerId(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="sandbox_server_id">Server/Zone ID (Optional)</Label>
          <Input id="sandbox_server_id" value={serverId} onChange={(e) => setServerId(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="sandbox_customer_email">Customer Email (Optional)</Label>
          <Input id="sandbox_customer_email" placeholder="Defaults to a fixed sandbox placeholder" value={customerEmail} onChange={(e) => setCustomerEmail(e.target.value)} />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || !gameId || !packageId}>
            {submitting ? "Creating…" : "Create Test Order"}
          </Button>
        </div>
      </form>
    </>
  );
}

export default function CreateSandboxOrderModal({ isOpen, onClose, onSubmit, token }: CreateSandboxOrderModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-md">
            <DialogHeader>
              <DialogTitle>Create Test Order</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && <CreateSandboxOrderFields onClose={onClose} onSubmit={onSubmit} token={token} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
