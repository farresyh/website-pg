import Link from "next/link";
import type { ReactNode } from "react";

interface BaseProps {
  children: ReactNode;
  size?: "sm" | "md";
  variant?: "primary" | "outline";
  startIcon?: ReactNode;
  className?: string;
}

type ButtonProps = BaseProps &
  (
    | { href: string; onClick?: never; type?: never; disabled?: never }
    | { href?: never; onClick?: () => void; type?: "button" | "submit"; disabled?: boolean }
  );

const sizeClasses = {
  sm: "px-3.5 py-2 text-sm",
  md: "px-5 py-2.75 text-[15px]",
};

const variantClasses = {
  primary: "bg-brand text-on-brand hover:bg-brand-light hover:text-brand-dark",
  outline: "bg-transparent text-text border border-border hover:border-brand",
};

/**
 * Every screen keeps exactly one filled `primary` button — outline is
 * for every secondary action (ui-ux-pro-max `primary-action` rule) —
 * matches the discipline already present in the original HTML draft.
 * 44px min height enforced via padding to meet the touch-target rule.
 */
export default function Button({ children, size = "md", variant = "primary", startIcon, className = "", ...rest }: ButtonProps) {
  const classes = `inline-flex min-h-11 items-center justify-center gap-2 rounded-lg font-semibold transition-colors duration-200 ${sizeClasses[size]} ${variantClasses[variant]} ${className}`;

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
      className={`${classes} ${disabled ? "cursor-not-allowed opacity-50" : "cursor-pointer"}`}
    >
      {startIcon}
      {children}
    </button>
  );
}
