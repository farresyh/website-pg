import React, { ReactNode } from "react";

// TODO(ADR-038): migrate to PrimeReact's `DataTable` (Tailwind mode) next time this file is opened for other work.
interface TableProps {
  children: ReactNode;
  className?: string;
}

interface TableCellProps {
  children: ReactNode;
  isHeader?: boolean;
  className?: string;
}

export const Table: React.FC<TableProps> = ({ children, className }) => (
  <table className={`min-w-full ${className ?? ""}`}>{children}</table>
);

export const TableHeader: React.FC<TableProps> = ({ children, className }) => (
  <thead className={className}>{children}</thead>
);

export const TableBody: React.FC<TableProps> = ({ children, className }) => (
  <tbody className={className}>{children}</tbody>
);

export const TableRow: React.FC<TableProps> = ({ children, className }) => (
  <tr className={className}>{children}</tr>
);

export const TableCell: React.FC<TableCellProps> = ({
  children,
  isHeader = false,
  className,
}) => {
  const CellTag = isHeader ? "th" : "td";
  return <CellTag className={className}>{children}</CellTag>;
};
