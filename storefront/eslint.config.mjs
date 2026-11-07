import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      // With no `sizes`, the browser assumes the image fills the screen: a
      // 280px card downloaded a 1920px file (ADR-078 2026-11-08 addendum).
      "no-restricted-syntax": [
        "error",
        {
          selector:
            "JSXOpeningElement[name.name='Image']:has(JSXAttribute[name.name='fill']):not(:has(JSXAttribute[name.name='sizes']))",
          message: "A fill Image needs sizes, or the browser downloads a screen-wide file.",
        },
      ],
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
