import Link from "next/link";
import { CheckCircle, WarningCircle, XCircle, ArrowSquareOut } from "@phosphor-icons/react/dist/ssr";
import type { GameDetail } from "@/lib/catalog";
import type { ValidatePlayerResult } from "@/lib/checkout";
import Button from "@/components/ui/Button";

interface Step1AccountInfoProps {
  /** ADR-097 decision 9 — needs GameDetail (not the narrower Game), for zoneOptions. */
  game: GameDetail;
  playerId: string;
  setPlayerId: (value: string) => void;
  serverId: string;
  setServerId: (value: string) => void;
  onVerify: () => void;
  onContinue: () => void;
  verifying: boolean;
  verifyError: string | null;
  result: ValidatePlayerResult | null;
}

const EXTRA_FIELD_LABEL: Record<"server_id" | "zone_id", string> = {
  server_id: "Server ID",
  zone_id: "Zone ID",
};

const inputClass =
  "min-h-11 rounded-md border-2 border-ink bg-surface-container-lowest px-3.5 text-sm text-on-surface placeholder:text-outline focus:border-secondary focus:outline-none";
const labelClass = "mb-1.5 block font-display text-[13px] font-bold";

/**
 * Step 1 of the wizard — improvised vs the reference: the reference
 * assumed every game has a "Verify Account" gate. Only games with a
 * real player-validator profile do (PlayerValidationController); for
 * the rest this renders as a plain "Continue" gate (presence check
 * only, no fake verify call) so every game still gets a consistent
 * 3-step wizard.
 */
export default function Step1AccountInfo({
  game,
  playerId,
  setPlayerId,
  serverId,
  setServerId,
  onVerify,
  onContinue,
  verifying,
  verifyError,
  result,
}: Step1AccountInfoProps) {
  const idReady = playerId.trim().length > 0 && (!game.extraField || serverId.trim().length > 0);

  return (
    <div>
      <div className={`grid gap-3 ${game.extraField ? "grid-cols-1 sm:grid-cols-2" : "grid-cols-1"}`}>
        <div>
          <label htmlFor="playerId" className={labelClass}>
            Player ID / User ID
          </label>
          <input
            id="playerId"
            type="text"
            value={playerId}
            onChange={(e) => setPlayerId(e.target.value)}
            placeholder="e.g. 123456789"
            inputMode="numeric"
            className={`${inputClass} w-full font-mono`}
          />
        </div>
        {game.extraField && (
          <div>
            <label htmlFor="serverId" className={labelClass}>
              {EXTRA_FIELD_LABEL[game.extraField]}
            </label>
            {/* ADR-097 decision 9/7 — a <select> once a real zone_options list exists for this game; unchanged free-text <input> otherwise (server_id, or a zone_id game with no list defined yet). */}
            {game.extraField === "zone_id" && game.zoneOptions ? (
              <select
                id="serverId"
                value={serverId}
                onChange={(e) => setServerId(e.target.value)}
                className={`${inputClass} w-full`}
              >
                <option value="" disabled>
                  Select a zone…
                </option>
                {game.zoneOptions.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            ) : (
              <input
                id="serverId"
                type="text"
                value={serverId}
                onChange={(e) => setServerId(e.target.value)}
                placeholder="e.g. 1234"
                className={`${inputClass} w-full font-mono`}
              />
            )}
          </div>
        )}
      </div>

      <div className="mt-3.5 flex justify-end">
        {game.playerValidatorEnabled ? (
          <Button type="button" variant="outline" size="sm" onClick={onVerify} disabled={verifying || !idReady}>
            {verifying ? "Verifying…" : "Verify Account"}
          </Button>
        ) : (
          <Button type="button" variant="outline" size="sm" onClick={onContinue} disabled={!idReady}>
            Continue
          </Button>
        )}
      </div>

      {verifyError && (
        <p className="mt-3 rounded-md border-2 border-ink bg-surface-container p-3 text-[13px] text-on-surface-variant">{verifyError}</p>
      )}

      {game.playerValidatorEnabled && result?.status === "valid" && (
        <p className="mt-3 flex items-center gap-2 rounded-md border-2 border-success bg-success p-3 text-[13px] text-on-success">
          <CheckCircle size={16} weight="fill" />
          Valid account found{result.nickname ? `: ${result.nickname}` : ""}.
        </p>
      )}

      {game.playerValidatorEnabled && result?.status === "invalid" && (
        <p className="mt-3 flex items-center gap-2 rounded-md border-2 border-ink bg-surface-container p-3 text-[13px] text-on-surface-variant">
          <XCircle size={16} weight="fill" className="shrink-0" />
          Account not found. Double-check your Player ID before continuing.
        </p>
      )}

      {game.playerValidatorEnabled && result?.status === "region_unknown" && (
        <p className="mt-3 flex items-center gap-2 rounded-md border-2 border-ink bg-surface-container p-3 text-[13px] text-on-surface-variant">
          <WarningCircle size={16} weight="fill" className="shrink-0" />
          We couldn&apos;t confirm this account&apos;s region. Contact WhatsApp support before paying.
        </p>
      )}

      {game.playerValidatorEnabled && result?.status === "wrong_region" && result.redirect_game && (
        <div className="mt-3 rounded-md border-2 border-ink bg-warning p-3 text-[13px] text-on-warning">
          <p className="mb-2 flex items-center gap-2 font-bold">
            <WarningCircle size={16} weight="fill" />
            Wrong Region Detected: this account belongs to {result.redirect_game.name}.
          </p>
          <Link href={`/order/${result.redirect_game.slug}`} className="inline-flex items-center gap-1 font-bold text-primary underline">
            Go to {result.redirect_game.name} Store <ArrowSquareOut size={14} />
          </Link>
        </div>
      )}
    </div>
  );
}
