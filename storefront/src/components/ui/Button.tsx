import Link from "next/link";
import type { ReactNode } from "react";

interface BaseProps {
  children: ReactNode;
  size?: "sm" | "md" | "lg";
  variant?: "primary" | "outline" | "text" | "destructive";
  startIcon?: ReactNode;
  className?: string;
}

type ButtonProps = BaseProps &
  (
    | { href: string; onClick?: never; type?: never; disabled?: never }
    | { href?: never; onClick?: () => void; type?: "button" | "submit"; disabled?: boolean }
  );

const sizeClasses = {
  sm: "px-4 py-2 text-[13px]",
  md: "px-5 py-2.75 text-[15px]",
  lg: "px-6 py-3.5 text-base",
};

/**
 * ADR-063/064 primitive. Neo-brutalist framing: 2px ink border + hard
 * offset shadow that shifts on hover. Every screen keeps exactly one
 * filled `primary` button (ui-ux-pro-max primary-action rule); `outline`
 * / `text` carry secondary actions, `destructive` the dangerous one.
 * CTAs are uppercase with wide tracking, per the design system.
 * 44px min height via padding for the touch-target rule.
 */
const variantClasses = {
  primary:
    "border-2 border-ink bg-primary text-on-primary neo neo-hover-cyan hover:bg-primary-container",
  outline:
    "border-2 border-ink bg-surface-container-lowest text-ink neo neo-hover hover:bg-surface-container-low",
  text: "border-2 border-transparent bg-transparent text-primary hover:bg-primary-fixed",
  destructive:
    "border-2 border-ink bg-danger text-on-danger neo neo-hover hover:brightness-95",
};

export default function Button({
  children,
  size = "md",
  variant = "primary",
  startIcon,
  className = "",
  ...rest
}: ButtonProps) {
  const classes = `inline-flex min-h-11 items-center justify-center gap-2 rounded-md font-display font-bold uppercase tracking-[0.05em] transition-all duration-150 ${sizeClasses[size]} ${variantClasses[variant]} ${className}`;

  if ("href" in rest && rest.href) {
    return (
      <Link href={rest.href} className={classes}>
        {startIcon}
        {children}
      </Link>
    );
  }

  const { onClick, type = "button", disabled = false } = rest as {
    onClick?: () => void;
    type?: "button" | "submit";
    disabled?: boolean;
  };

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`${classes} ${disabled ? "cursor-not-allowed opacity-50 hover:translate-x-0 hover:translate-y-0" : "cursor-pointer"}`}
    >
      {startIcon}
      {children}
    </button>
  );
}
