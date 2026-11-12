// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightOpenAPI, { openAPISidebarGroups } from 'starlight-openapi';
import { guideSidebar } from './src/guide-sidebar.mjs';

// ADR-084 PR-4 — the Reseller API developer docs, deployed to
// docs.pekangame.space (Vercel, 4th frontend). Static output; no adapter
// (Vercel auto-detects Astro static). The API Reference pages are
// generated at build time from ./public/openapi.json, which is committed
// and regenerated from the backend's Scramble annotations — a CI job
// fails the build on any drift between the two.
export default defineConfig({
	site: 'https://docs.pekangame.space',
	integrations: [
		starlight({
			title: 'PekanGame Reseller API',
			// PekanGame's own favicon (Admin → Branding on pekangame.com, served at
			// pekangame.com/icon as WebP). Starlight takes only .png/.svg/.ico, so this
			// is a PNG copy: refresh it if the brand favicon changes.
			favicon: '/favicon.png',
			description:
				'Place orders, check status, read the price list and Purchase Balance for a PekanGame prepaid reseller account.',
			social: [
				{ icon: 'external', label: 'PekanGame', href: 'https://pekangame.space' },
			],
			plugins: [
				starlightOpenAPI([
					{
						base: 'reference',
						label: 'API Reference',
						schema: './public/openapi.json',
					},
				]),
			],
			sidebar: [
				...guideSidebar,
				...openAPISidebarGroups,
			],
		}),
	],
});
