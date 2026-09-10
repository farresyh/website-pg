// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightOpenAPI, { openAPISidebarGroups } from 'starlight-openapi';

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
			description:
				'Place orders, check status, read the price list and wallet balance for a PekanGame prepaid-wallet reseller account.',
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
				{
					label: 'Start here',
					items: [
						{ label: 'Introduction', slug: 'introduction' },
						{ label: 'Authentication', slug: 'authentication' },
						{ label: 'Your first order', slug: 'first-order' },
					],
				},
				{
					label: 'Guides',
					items: [
						{ label: 'Product codes', slug: 'product-codes' },
						{ label: 'Idempotency & retries', slug: 'idempotency' },
						{ label: 'Delivery notifications', slug: 'webhooks' },
						{ label: 'Wallet & balance', slug: 'wallet' },
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'Errors', slug: 'errors' },
						{ label: 'Rate limits', slug: 'rate-limits' },
						{ label: 'Versioning & changelog', slug: 'versioning' },
					],
				},
				...openAPISidebarGroups,
			],
		}),
	],
});
