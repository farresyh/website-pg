"use client";

import React, { createContext, useContext, useState } from "react";

/**
 * Shared between SiteHeader's search input and PopularPicksSection's
 * grid — the only real cross-component interaction on the homepage,
 * so a small context beats prop-drilling through the whole tree.
 * Filters the local placeholder catalog for now; swaps to a real
 * query param / API call once the public catalog endpoint exists.
 */
interface SearchContextValue {
  query: string;
  setQuery: (query: string) => void;
}

const SearchContext = createContext<SearchContextValue | null>(null);

export function SearchProvider({ children }: { children: React.ReactNode }) {
  const [query, setQuery] = useState("");

  return <SearchContext.Provider value={{ query, setQuery }}>{children}</SearchContext.Provider>;
}

export function useSearch(): SearchContextValue {
  const ctx = useContext(SearchContext);
  if (!ctx) throw new Error("useSearch must be used within SearchProvider");
  return ctx;
}
