import { test } from "node:test";
import assert from "node:assert/strict";
import { faqPageJsonLd } from "./faq-json-ld.ts";

// ADR-120 decision 13 + ADR-140 decision 9: one builder, so the structured
// data always carries the same questions the page renders.

test("no items gives no structured data", () => {
  assert.equal(faqPageJsonLd([]), null);
});

test("each item becomes a Question with an accepted Answer, in order", () => {
  const ld = faqPageJsonLd([
    { question: "Macam mana cari Zone ID?", answer: "Buka profil dalam game." },
    { question: "Berapa lama?", answer: "Ikut mod penghantaran." },
  ]);
  assert.deepEqual(ld, {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: [
      { "@type": "Question", name: "Macam mana cari Zone ID?", acceptedAnswer: { "@type": "Answer", text: "Buka profil dalam game." } },
      { "@type": "Question", name: "Berapa lama?", acceptedAnswer: { "@type": "Answer", text: "Ikut mod penghantaran." } },
    ],
  });
});
