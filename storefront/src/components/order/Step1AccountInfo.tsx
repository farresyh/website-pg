import Link from "next/link";
import { useState } from "react";
import { CheckCircle, WarningCircle, XCircle, ArrowSquareOut } from "@phosphor-icons/react/dist/ssr";
import { isLoginGame, type GameDetail } from "@/lib/catalog";
import LoginAccountFields, { loginFormReady } from "@/components/order/LoginAccountFields";
import type { ValidatePlayerResult } from "@/lib/checkout";
import Button from "@/components/ui/Button";
import GameInfoModal, { GameInfoTriggerButton } from "@/components/order/GameInfoModal";
import { digitsOnlyHandlers, idInputClass as inputClass, idLabelClass as labelClass, inputError } from "@/components/order/idFields";

interface Step1AccountInfoProps {
  /** ADR-097 decision 9 — needs GameDetail (not the narrower Game), for zoneOptions. */
  game: GameDetail;
  playerId: string;
  setPlayerId: (value: string) => void;
  serverId: string;
  setServerId: (value: string) => void;
  onVerify: (zone?: string) => void;
  onContinue: () => void;
  verifying: boolean;
  verifyError: string | null;
  result: ValidatePlayerResult | null;
  /** ADR-125: a login game's form values and consent, owned by OrderForm. */
  credentials: Record<string, string>;
  setCredential: (key: string, value: string) => void;
  credentialConsent: boolean;
  setCredentialConsent: (value: boolean) => void;
}

const EXTRA_FIELD_LABEL: Record<"server_id" | "zone_id", string> = {
  server_id: "Server ID",
  zone_id: "Zone ID",
};


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
  credentials,
  setCredential,
  credentialConsent,
  setCredentialConsent,
}: Step1AccountInfoProps) {
  const loginGame = isLoginGame(game);
  const playerIdError = inputError(playerId, "User ID", game.playerIdFormat === "text" ? "text" : "digits");
  const serverIdError = game.extraField
    ? inputError(serverId, EXTRA_FIELD_LABEL[game.extraField], game.extraField === "server_id" ? "digits" : "any")
    : null;
  const idReady = loginGame
    ? loginFormReady(game.credentialFields, credentials, credentialConsent)
    : playerId.trim().length > 0 &&
      (!game.extraField || serverId.trim().length > 0) &&
      playerIdError === null &&
      serverIdError === null;

  // ADR-109 decisions 8/9/10 — auto-opens once per page load, only when
  // there's real content to show; the manual re-open button is gated
  // by the identical check. Lazy initializer, not an effect — `game`
  // never changes after mount, so there's nothing to synchronize.
  const hasInfoContent = Boolean(game.description) || game.importantNotes.length > 0;
  const [infoOpen, setInfoOpen] = useState(() => hasInfoContent);

  return (
    <div>
      {infoOpen && (
        <GameInfoModal
          gameName={game.name}
          description={game.description}
          importantNotes={game.importantNotes}
          onClose={() => setInfoOpen(false)}
        />
      )}
      {loginGame ? (
        <LoginAccountFields
          fields={game.credentialFields}
          values={credentials}
          setValue={setCredential}
          consentText={game.credentialConsentText}
          consent={credentialConsent}
          setConsent={setCredentialConsent}
        />
      ) : (
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
            {...(game.playerIdFormat === "text" ? {} : digitsOnlyHandlers)}
            placeholder={game.playerIdFormat === "text" ? "e.g. JettMain#1234" : "e.g. 123456789"}
            inputMode={game.playerIdFormat === "text" ? "text" : "numeric"}
            aria-invalid={playerIdError !== null}
            aria-describedby={playerIdError ? "playerIdError" : undefined}
            className={`${inputClass} w-full font-mono`}
          />
          {playerIdError && (
            <p id="playerIdError" role="alert" className="mt-1.5 text-[13px] font-medium text-danger">
              {playerIdError}
            </p>
          )}
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
                {...(game.extraField === "server_id" ? digitsOnlyHandlers : {})}
                placeholder="e.g. 1234"
                inputMode={game.extraField === "server_id" ? "numeric" : "text"}
                aria-invalid={serverIdError !== null}
                aria-describedby={serverIdError ? "serverIdError" : undefined}
                className={`${inputClass} w-full font-mono`}
              />
            )}
            {serverIdError && (
              <p id="serverIdError" role="alert" className="mt-1.5 text-[13px] font-medium text-danger">
                {serverIdError}
              </p>
            )}
          </div>
        )}
      </div>
      )}

      <div className={`mt-3.5 flex items-center ${hasInfoContent ? "justify-between" : "justify-end"}`}>
        {hasInfoContent && <GameInfoTriggerButton onClick={() => setInfoOpen(true)} />}
        {game.playerValidatorEnabled && !loginGame ? (
          <Button type="button" variant="outline" size="sm" onClick={() => onVerify()} disabled={verifying || !idReady}>
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

      {game.playerValidatorEnabled && result?.status === "wrong_zone" && (
        <div className="mt-3 rounded-md border-2 border-ink bg-warning p-3 text-[13px] text-on-warning">
          <p className="flex items-center gap-2 font-bold">
            <WarningCircle size={16} weight="fill" className="shrink-0" />
            {result.zone
              ? `This UID is on the ${result.server} server, not ${serverId}.`
              : `This UID is on the ${result.server} server, which this store doesn't sell.`}
          </p>
          {result.zone && (
            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => onVerify(result.zone ?? undefined)} disabled={verifying}>
              Switch to {result.zone}
            </Button>
          )}
        </div>
      )}

      {game.playerValidatorEnabled && result?.status === "wrong_region" && result.redirect_game && (
        <div className="mt-3 rounded-md border-2 border-ink bg-warning p-3 text-[13px] text-on-warning">
          <p className="mb-2 flex items-center gap-2 font-bold">
            <WarningCircle size={16} weight="fill" />
            Wrong Region Detected: this account belongs to {result.redirect_game.name}.
          </p>
          <Link href={`/order/${result.redirect_game.slug}`} className="inline-flex items-center gap-1 font-bold text-primary-on-surface underline">
            Go to {result.redirect_game.name} Store <ArrowSquareOut size={14} />
          </Link>
        </div>
      )}
    </div>
  );
}
