"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
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
    setPackageId(null);
    setPackages(null);
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
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Create Test Order</h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Creates a real Order row, marked as sandbox-only (is_test), paid immediately with no Xendit call, and starting
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
            <Select
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
            <Select
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
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting || !gameId || !packageId}>
            {submitting ? "Creating…" : "Create Test Order"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function CreateSandboxOrderModal({ isOpen, onClose, onSubmit, token }: CreateSandboxOrderModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && <CreateSandboxOrderFields onClose={onClose} onSubmit={onSubmit} token={token} />}
    </Modal>
  );
}
