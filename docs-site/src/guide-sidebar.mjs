// The hand-written guide pages, in reading order. One list for the Starlight
// sidebar (astro.config.mjs) and for /llms.txt (src/pages/llms.txt.ts), so a new
// page is added once and appears in both.
export const guideSidebar = [
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
			{ label: 'Purchase Balance', slug: 'wallet' },
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
];
