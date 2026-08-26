import { cn as _cn } from "@primeuix/utils";
import { twMerge } from "tailwind-merge";

/**
 * ADR-038: required by every PrimeReact-Tailwind component to merge
 * class names — see primereact.dev/docs/tailwind/guides/installation/nextjs.
 */
export function cn(...inputs: unknown[]) {
  return twMerge(_cn(...inputs));
}
