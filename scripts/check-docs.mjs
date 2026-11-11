#!/usr/bin/env node
// Mechanical docs rules (docs/adr.md "How to use and maintain this log",
// PRD §16 preamble). Catches the drift that docs audits kept finding by hand.
// Also: nothing private reaches the public Reseller API docs (docs-site).
// Run: node scripts/check-docs.mjs            (repo check, exit 1 on a finding)
//      node scripts/check-docs.mjs --self-test
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import assert from 'node:assert/strict';

const ROOT = resolve(dirname(new URL(import.meta.url).pathname), '..');
const STANDING = /^(In force|Partly superseded|Superseded|Reserved number)\b/;
const DATE = /\d{4}-\d{2}-\d{2}/;

// The last dated addendum in an ADR file: a `### YYYY-MM-DD …` heading, an
// older `### Addendum (YYYY-MM-DD)` heading, or a bold-paragraph addendum
// (`**Addendum — …, YYYY-MM-DD**`).
export function lastAddendum(text) {
  const dates = [];
  for (const line of text.split('\n')) {
    const h = line.match(/^###\s+(\d{4}-\d{2}-\d{2})/);
    const old = /^(###\s.*addendum|\*\*[^*]*addendum)/i.test(line) && line.match(DATE);
    if (h) dates.push(h[1]);
    else if (old) dates.push(old[0]);
  }
  return dates.sort().at(-1) ?? '';
}

// PRD §16: item numbers per part. Next up uses "- **N**", Details "N. **",
// Recently closed "- **N** date:".
export function section16(prd) {
  const part = (from, to) => prd.slice(prd.indexOf(from), to ? prd.indexOf(to) : undefined);
  const nums = (text, re) => [...text.matchAll(re)].map((m) => Number(m[1]));
  const next = part('## Next up', '## Details');
  const details = part('## Details', '## Recently closed');
  const closed = part('## Recently closed', '## Parked by founder decision');
  return {
    next: nums(next, /^- \*\*(\d+)\*\*/gm),
    details: nums(details, /^(\d+)\. \*\*/gm),
    closed: nums(closed, /^- \*\*(\d+)\*\* \d{4}-/gm),
  };
}

// docs.pekangame.space is read by outside developers. Its prose and the spec
// (generated from PHP docblocks, so an internal comment can leak into it) must
// never name a supplier, the platform's margin, an internal reference or the
// infrastructure. A hit fails CI; reword the source, never this list.
const PRIVATE_TERMS = /\b(digiflazz|gamevion|markup|cost_price|standard_selling_price|\w*profit|ADR-\d+|ORD-\d+|forge|horizon|kedairuncit|lwf)\b|159\.223\./gi;

export function privateTerms(text) {
  return [...new Set([...text.matchAll(PRIVATE_TERMS)].map((m) => m[0]))];
}

function checkPublicDocs(problems) {
  const dir = 'docs-site/src/content/docs';
  const files = [
    ...readdirSync(join(ROOT, dir)).filter((f) => /\.mdx?$/.test(f)).map((f) => join(dir, f)),
    'docs-site/public/openapi.json',
    'docs-site/src/pages/llms.txt.ts',
  ];
  for (const rel of files) {
    const hits = privateTerms(readFileSync(join(ROOT, rel), 'utf8'));
    if (hits.length) problems.push(`${rel}: public docs name private terms: ${hits.join(', ')}`);
  }
}

function checkAdrs(problems) {
  const index = readFileSync(join(ROOT, 'docs/adr.md'), 'utf8');
  const rows = [...index.matchAll(/^\| \[ADR-(\d{3})\]\(\.\/adr\/([^)]+)\) \| .*? \| (.*?) \| (.*?) \|$/gm)];
  const files = readdirSync(join(ROOT, 'docs/adr')).filter((f) => /^ADR-\d{3}-.*\.md$/.test(f));
  const byFile = new Map(rows.map((r) => [r[2], r]));
  for (const f of files) if (!byFile.has(f)) problems.push(`docs/adr.md: no index row for ${f}`);
  for (const [, num, file, standing, last] of rows) {
    const path = join(ROOT, 'docs/adr', file);
    if (!existsSync(path)) { problems.push(`docs/adr.md: ADR-${num} links to missing ${file}`); continue; }
    const text = readFileSync(path, 'utf8');
    if (!STANDING.test(standing)) problems.push(`docs/adr.md: ADR-${num} Standing starts "${standing.slice(0, 30)}"`);
    const banner = text.match(/^> \*\*Standing \([^)]*\):\*\* (.*)$/m);
    if (banner && !STANDING.test(banner[1])) problems.push(`${file}: banner Standing starts "${banner[1].slice(0, 30)}"`);
    const want = lastAddendum(text);
    if (want !== last.trim()) problems.push(`docs/adr.md: ADR-${num} Last addendum "${last.trim()}", file's last dated addendum "${want}"`);
  }
}

function checkLinks(problems) {
  const docs = (dir) => readdirSync(join(ROOT, dir)).filter((f) => f.endsWith('.md')).map((f) => join(dir, f));
  const targets = [...docs('docs'), ...docs('docs/adr'), 'AGENTS.md', 'backend/AGENTS.md', 'reseller/AGENTS.md'];
  for (const rel of targets) {
    const text = readFileSync(join(ROOT, rel), 'utf8').replace(/```[\s\S]*?```/g, '');
    for (const [, link] of text.matchAll(/\]\(([^)\s#]+\.md)(?:#[^)]*)?\)/g)) {
      if (/^[a-z]+:/i.test(link) || link.includes('NNN')) continue;
      if (!existsSync(resolve(ROOT, dirname(rel), link))) problems.push(`${rel}: broken link ${link}`);
    }
  }
}

function checkBacklog(problems) {
  const { next, details, closed } = section16(readFileSync(join(ROOT, 'docs/prd.md'), 'utf8'));
  const dupes = next.filter((n, i) => next.indexOf(n) !== i);
  if (dupes.length) problems.push(`prd.md §16: Next up lists ${dupes.join(', ')} twice`);
  for (const n of details) {
    if (!next.includes(n)) problems.push(`prd.md §16: Details has ${n}, which is not in Next up`);
    if (closed.includes(n)) problems.push(`prd.md §16: item ${n} is under Recently closed but still in Details`);
  }
  for (const n of next) if (closed.includes(n)) problems.push(`prd.md §16: item ${n} is in Next up and Recently closed`);
}

function selfTest() {
  assert.equal(lastAddendum('### 2026-10-01 addendum — a\n**Build addendum, 2026-11-02 — b**\n### 2026-09-01 note'), '2026-11-02');
  assert.equal(lastAddendum('no addenda here, 2026-01-01'), '');
  assert.equal(lastAddendum('**Context:** see the addendum of 2026-01-01'), '');
  assert.equal(lastAddendum('### Addendum (2026-09-24) — x'), '2026-09-24');
  const s = section16('## Next up\n- **4** a\n- **9** b\n## Details\n4. **A**\n## Recently closed\n- **7** 2026-10-01: x\n## Parked by founder decision\n');
  assert.deepEqual(s, { next: [4, 9], details: [4], closed: [7] });
  assert.deepEqual(privateTerms('Check the new price against your own margin.'), []);
  assert.deepEqual(privateTerms('ADR-084: Digiflazz cost_price and platform_profit'), ['ADR-084', 'Digiflazz', 'cost_price', 'platform_profit']);
  console.log('check-docs self-test passed');
}

if (process.argv.includes('--self-test')) selfTest();
else {
  const problems = [];
  checkAdrs(problems);
  checkLinks(problems);
  checkBacklog(problems);
  checkPublicDocs(problems);
  for (const p of problems) console.error(`::error::${p}`);
  console.log(problems.length ? `${problems.length} docs problem(s)` : 'docs check passed');
  process.exit(problems.length ? 1 : 0);
}
