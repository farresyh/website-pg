import React from "react";

interface Option {
  value: string;
  label: string;
}

interface SelectProps {
  options: Option[];
  value: string;
  onChange: (value: string) => void;
  className?: string;
  id?: string;
}

const Select: React.FC<SelectProps> = ({ options, value, onChange, className = "", id }) => (
  <select
    id={id}
    className={`h-11 w-full appearance-none rounded-lg border border-gray-300 px-4 py-2.5 pr-11 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800 text-gray-800 dark:text-white/90 ${className}`}
    value={value}
    onChange={(e) => onChange(e.target.value)}
  >
    {options.map((option) => (
      <option key={option.value} value={option.value} className="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
        {option.label}
      </option>
    ))}
  </select>
);

export default Select;
