import React, { FC, ReactNode } from "react";

// TODO(ADR-038): migrate to PrimeReact's `Label` (Tailwind mode) next time this file is opened for other work — lower priority, see ADR-038.
interface LabelProps {
  htmlFor?: string;
  children: ReactNode;
  className?: string;
}

const Label: FC<LabelProps> = ({ htmlFor, children, className }) => (
  <label
    htmlFor={htmlFor}
    className={`mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400 ${className ?? ""}`}
  >
    {children}
  </label>
);

export default Label;
