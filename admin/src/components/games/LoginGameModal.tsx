"use client";

import React, { useState } from "react";
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
import { Button } from "@/components/ui/button";
import CredentialSchemaEditor, { DEFAULT_CREDENTIAL_FIELDS } from "@/components/games/CredentialSchemaEditor";
import type { CreateLoginGameValues, CredentialField, Game } from "@/lib/games";

type Mode = { kind: "create" } | { kind: "schema"; game: Game };

/**
 * ADR-125 decisions 5/6/33: creates a login game (Roblox, eFootball) with
 * its checkout form, or edits an existing login game's form. A login game
 * starts inactive and delivered manually; add manual packages, then
 * switch it on in Edit Game.
 */
function LoginGameFields({
  mode,
  onClose,
  onCreate,
  onSaveSchema,
}: {
  mode: Mode;
  onClose: () => void;
  onCreate: (values: CreateLoginGameValues) => Promise<void>;
  onSaveSchema: (fields: CredentialField[]) => Promise<void>;
}) {
  const [name, setName] = useState("");
  const [category, setCategory] = useState("");
  const [deliverySubtext, setDeliverySubtext] = useState("Manual processing: usually within 30 minutes");
  const [memberPricing, setMemberPricing] = useState(false);
  const [fields, setFields] = useState<CredentialField[]>(
    mode.kind === "schema" ? (mode.game.validation_rules?.credential_fields ?? []) : DEFAULT_CREDENTIAL_FIELDS,
  );
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (fields.filter((f) => f.is_identifier).length !== 1) {
      setError("Choose exactly one identifier field.");
      return;
    }
    setSubmitting(true);
    try {
      if (mode.kind === "create") {
        await onCreate({
          name,
          category: category || null,
          delivery_subtext: deliverySubtext.trim(),
          member_pricing_enabled: memberPricing,
          credential_fields: fields,
        });
      } else {
        await onSaveSchema(fields);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      {mode.kind === "create" && (
        <>
          <div>
            <Label htmlFor="login_game_name">Name</Label>
            <Input id="login_game_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Roblox" required />
          </div>
          <div>
            <Label htmlFor="login_game_category">Category (optional)</Label>
            <Input id="login_game_category" value={category} onChange={(e) => setCategory(e.target.value)} />
          </div>
          <div>
            <Label htmlFor="login_game_subtext">Delivery Subtext</Label>
            <Input id="login_game_subtext" value={deliverySubtext} onChange={(e) => setDeliverySubtext(e.target.value)} required />
            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
              Shown beside the &quot;Manual Processing&quot; badge. Required: the default promises 1–3 minutes.
            </p>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" checked={memberPricing} onChange={(e) => setMemberPricing(e.target.checked)} />
            Member pricing (members get their discount on this game)
          </label>
        </>
      )}

      <div className={mode.kind === "create" ? "border-t border-gray-100 pt-4 dark:border-gray-800" : ""}>
        <Label>Login form</Label>
        <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
          What the customer fills in at checkout. Secret fields are encrypted and wiped after the order closes; only a
          Super Admin can reveal them.
        </p>
        <CredentialSchemaEditor fields={fields} onChange={setFields} />
      </div>

      <div className="flex justify-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
        <Button type="button" variant="outlined" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="submit" disabled={submitting}>
          {submitting ? "Saving…" : mode.kind === "create" ? "Create Login Game" : "Save Login Form"}
        </Button>
      </div>
    </form>
  );
}

export default function LoginGameModal({
  mode,
  onClose,
  onCreate,
  onSaveSchema,
}: {
  mode: Mode | null;
  onClose: () => void;
  onCreate: (values: CreateLoginGameValues) => Promise<void>;
  onSaveSchema: (fields: CredentialField[]) => Promise<void>;
}) {
  return (
    <Dialog open={mode !== null} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-2xl">
            <DialogHeader>
              <DialogTitle>{mode?.kind === "schema" ? `Login Form — ${mode.game.name}` : "New Login Game"}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {mode && <LoginGameFields mode={mode} onClose={onClose} onCreate={onCreate} onSaveSchema={onSaveSchema} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
