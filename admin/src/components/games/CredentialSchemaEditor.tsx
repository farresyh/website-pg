"use client";

import React from "react";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { CREDENTIAL_FIELD_TYPES, type CredentialField } from "@/lib/games";

/** A sensible start: the login most top-up suppliers ask for. */
export const DEFAULT_CREDENTIAL_FIELDS: CredentialField[] = [
  { key: "email", label: "Login email", type: "email", required: true, secret: false, is_identifier: true },
  { key: "password", label: "Password", type: "password", required: true, secret: true, is_identifier: false },
];

/** Stable machine key from the label; the backend requires `^[a-z][a-z0-9_]{0,31}$`. */
function keyFromLabel(label: string, taken: string[]): string {
  const base = label.toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^[^a-z]+|_+$/g, "").slice(0, 28) || "field";
  let key = base;
  for (let i = 2; taken.includes(key); i++) key = `${base}_${i}`;
  return key;
}

/**
 * ADR-125 decision 6: the per-game login form. Exactly one non-secret
 * field is the identifier (it becomes the order's player_id); a password
 * is always secret. The backend enforces both; this editor keeps the
 * form in that shape so the admin rarely meets the error.
 */
export default function CredentialSchemaEditor({
  fields,
  onChange,
}: {
  fields: CredentialField[];
  onChange: (fields: CredentialField[]) => void;
}) {
  function update(index: number, patch: Partial<CredentialField>) {
    onChange(
      fields.map((f, i) => {
        if (i !== index) {
          // Choosing a new identifier clears the old one.
          return patch.is_identifier ? { ...f, is_identifier: false } : f;
        }
        const next = { ...f, ...patch };
        if (next.type === "password") next.secret = true;
        if (next.is_identifier) {
          next.secret = false;
          next.required = true;
        }
        if (next.type !== "select") delete next.options;
        return next;
      }),
    );
  }

  function add() {
    const key = keyFromLabel("New field", fields.map((f) => f.key));
    onChange([...fields, { key, label: "New field", type: "text", required: false, secret: false, is_identifier: false }]);
  }

  return (
    <div className="space-y-3">
      {fields.map((field, index) => (
        <div key={index} className="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
          <div className="grid gap-3 sm:grid-cols-[1fr_9rem_auto]">
            <Input
              aria-label="Field label"
              value={field.label}
              onChange={(e) => {
                const others = fields.filter((_, i) => i !== index).map((f) => f.key);
                update(index, { label: e.target.value, key: keyFromLabel(e.target.value, others) });
              }}
              placeholder="Label shown to the customer"
              required
            />
            <SimpleSelect
              value={field.type}
              onChange={(value) => update(index, { type: value as CredentialField["type"], ...(value === "select" ? { options: field.options ?? [] } : {}) })}
              options={CREDENTIAL_FIELD_TYPES.filter((t) => !field.is_identifier || t.value === "email" || t.value === "text")}
            />
            <Button
              type="button"
              size="small"
              variant="outlined"
              severity="danger"
              onClick={() => onChange(fields.filter((_, i) => i !== index))}
              disabled={field.is_identifier}
            >
              Remove
            </Button>
          </div>

          {field.type === "select" && (
            <Input
              className="mt-3"
              aria-label="Choices"
              value={(field.options ?? []).join(", ")}
              onChange={(e) => update(index, { options: e.target.value.split(",").map((o) => o.trim()).filter(Boolean) })}
              placeholder="Choices, comma separated (e.g. Email, Google, Facebook)"
            />
          )}

          <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-gray-700 dark:text-gray-300">
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="credential_identifier"
                checked={field.is_identifier}
                disabled={field.type === "password" || field.type === "select"}
                onChange={() => update(index, { is_identifier: true })}
              />
              Identifier (masked on order pages)
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={field.required}
                disabled={field.is_identifier}
                onChange={(e) => update(index, { required: e.target.checked })}
              />
              Required
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={field.secret}
                disabled={field.is_identifier || field.type === "password"}
                onChange={(e) => update(index, { secret: e.target.checked })}
              />
              Secret (encrypted, wiped after the order)
            </label>
          </div>
        </div>
      ))}

      <Button type="button" size="small" variant="outlined" onClick={add} disabled={fields.length >= 8}>
        Add field
      </Button>
    </div>
  );
}
