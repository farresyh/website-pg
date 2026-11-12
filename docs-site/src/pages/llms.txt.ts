import type { APIRoute } from 'astro';
import { getEntry } from 'astro:content';
import { guideSidebar } from '../guide-sidebar.mjs';

// /llms.txt (llmstxt.org): a markdown index for AI assistants that read a site on
// a developer's behalf. Built from the sidebar list and each page's own title and
// description, so it cannot drift from the pages. A missing page fails the build.
export const GET: APIRoute = async ({ site }) => {
	const origin = site!.origin;
	const lines = [
		'# PekanGame Reseller API',
		'',
		'> A small REST API for placing game top-up orders against a prepaid PekanGame Purchase Balance: price list, orders, order status, balance, and a signed delivery webhook.',
		'',
		`- [OpenAPI 3.1 spec](${origin}/openapi.json): the machine-readable contract, generated from the API's code`,
		'',
	];

	for (const group of guideSidebar) {
		lines.push(`## ${group.label}`, '');
		for (const item of group.items) {
			const page = await getEntry('docs', item.slug);
			if (!page) throw new Error(`llms.txt: no docs page "${item.slug}"`);
			lines.push(`- [${page.data.title}](${origin}/${item.slug}/): ${page.data.description}`);
		}
		lines.push('');
	}

	return new Response(lines.join('\n'), { headers: { 'Content-Type': 'text/markdown; charset=utf-8' } });
};
