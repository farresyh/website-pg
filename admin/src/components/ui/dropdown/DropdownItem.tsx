import type React from "react";

// TODO(ADR-038): migrate to PrimeReact's `Menu`/`Popover` (Tailwind mode) next time this file is opened for other work.
interface DropdownItemProps {
  onClick?: () => void;
  className?: string;
  children: React.ReactNode;
}

export const DropdownItem: React.FC<DropdownItemProps> = ({
  onClick,
  className = "",
  children,
}) => (
  <button
    onClick={onClick}
    className={`block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 ${className}`}
  >
    {children}
  </button>
);
