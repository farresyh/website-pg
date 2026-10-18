import { useState } from "react";
import { Eye, EyeSlash } from "@phosphor-icons/react/dist/ssr";
import type { CredentialField } from "@/lib/catalog";
import { idInputClass as inputClass, idLabelClass as labelClass } from "@/components/order/idFields";

/** ADR-125 decision 6: the identifier, trimmed — what the order shows (masked) as the account. */
export function loginIdentifier(fields: CredentialField[], values: Record<string, string>): string {
  const field = fields.find((f) => f.isIdentifier);
  return field ? (values[field.key] ?? "").trim() : "";
}

/** Mirrors CheckoutInputValidator::validateCredentials as an inline hint; the backend stays the authority. */
export function loginFieldError(field: CredentialField, value: string): string | null {
  const v = field.secret ? value : value.trim();
  if (v === "") return null;
  if (v.length > 255) return `${field.label} must be at most 255 characters.`;
  if (field.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return `${field.label} must be a valid email address.`;
  if (field.isIdentifier && /\s/.test(v)) return `${field.label} must not contain spaces.`;
  return null;
}

export function loginFormReady(fields: CredentialField[], values: Record<string, string>, consent: boolean): boolean {
  return (
    consent &&
    fields.every((f) => {
      const value = values[f.key] ?? "";
      const filled = (f.secret ? value : value.trim()) !== "";
      return (!f.required || filled) && loginFieldError(f, value) === null;
    })
  );
}

function PasswordInput({ id, value, onChange, invalid }: { id: string; value: string; onChange: (v: string) => void; invalid: boolean }) {
  const [shown, setShown] = useState(false);
  return (
    <div className="relative">
      <input
        id={id}
        type={shown ? "text" : "password"}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        autoComplete="off"
        aria-invalid={invalid}
        className={`${inputClass} w-full pr-11`}
      />
      <button
        type="button"
        onClick={() => setShown((s) => !s)}
        aria-label={shown ? "Hide password" : "Show password"}
        className="absolute top-1/2 right-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center text-on-surface-variant"
      >
        {shown ? <EyeSlash size={18} /> : <Eye size={18} />}
      </button>
    </div>
  );
}

/**
 * ADR-125 decisions 6/32 + ADR-124 decision 8: a login game's Step 1 —
 * the game's own login form, rendered from its schema for every brand,
 * plus the required consent box. Values live only in React state, never
 * in storage; secret fields are never echoed anywhere else.
 */
export default function LoginAccountFields({
  fields,
  values,
  setValue,
  consentText,
  consent,
  setConsent,
}: {
  fields: CredentialField[];
  values: Record<string, string>;
  setValue: (key: string, value: string) => void;
  consentText: string | null;
  consent: boolean;
  setConsent: (value: boolean) => void;
}) {
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {fields.map((field) => {
          const id = `login_${field.key}`;
          const value = values[field.key] ?? "";
          const error = loginFieldError(field, value);
          return (
            <div key={field.key}>
              <label htmlFor={id} className={labelClass}>
                {field.label}
                {!field.required && <span className="font-normal normal-case text-on-surface-variant"> (optional)</span>}
              </label>
              {field.type === "password" ? (
                <PasswordInput id={id} value={value} onChange={(v) => setValue(field.key, v)} invalid={error !== null} />
              ) : field.type === "select" ? (
                <select id={id} value={value} onChange={(e) => setValue(field.key, e.target.value)} className={`${inputClass} w-full`}>
                  <option value="" disabled={field.required}>
                    Select…
                  </option>
                  {(field.options ?? []).map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  id={id}
                  type={field.type === "email" ? "email" : "text"}
                  value={value}
                  onChange={(e) => setValue(field.key, e.target.value)}
                  autoComplete="off"
                  inputMode={field.type === "email" ? "email" : "text"}
                  aria-invalid={error !== null}
                  aria-describedby={error ? `${id}_error` : undefined}
                  className={`${inputClass} w-full`}
                />
              )}
              {error && (
                <p id={`${id}_error`} role="alert" className="mt-1.5 text-[13px] font-medium text-danger">
                  {error}
                </p>
              )}
            </div>
          );
        })}
      </div>

      {consentText && (
        <label className="flex items-start gap-2.5 rounded-md border-2 border-ink/15 bg-surface-container p-3 text-[13px] text-on-surface">
          <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-0.5 h-4 w-4 shrink-0" />
          <span>{consentText}</span>
        </label>
      )}
    </div>
  );
}
