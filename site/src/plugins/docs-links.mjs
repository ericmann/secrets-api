import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Sätteri mdast plugin factory: rewrite relative links written for GitHub so
 * they work on the built site.
 *
 * - A link to a Markdown file inside docs/ becomes that page's route.
 * - A link to anything else that exists in the repo (a PHP file, a directory,
 *   the root README) becomes a GitHub URL, since it has no page here.
 * - Anything unresolvable is left alone.
 *
 * @param {{docsDir: string, repoRoot: string, repoUrl: string, repoBranch: string}} options
 */
export function docsLinks({ docsDir, repoRoot, repoUrl, repoBranch }) {
	return ({ fileURL }) => {
		if (!fileURL) return;
		const fromDir = path.dirname(fileURLToPath(fileURL));

		return {
			name: 'docs-links',
			link(node, ctx) {
				const url = node.url;
				if (!url || /^(?:[a-z][a-z0-9+.-]*:|\/\/|\/|#)/i.test(url)) return;

				const [target, hash = ''] = url.split('#');
				const abs = path.resolve(fromDir, decodeURI(target));
				if (!fs.existsSync(abs)) return;
				const suffix = hash ? `#${hash}` : '';

				const inDocs = !path.relative(docsDir, abs).startsWith('..');
				if (inDocs && /\.mdx?$/.test(abs)) {
					let route = path.relative(docsDir, abs).replace(/\.mdx?$/, '');
					if (path.basename(route) === 'index') route = path.dirname(route);
					route = route === '.' ? '' : route.split(path.sep).join('/') + '/';
					ctx.setProperty(node, 'url', '/' + route + suffix);
					return;
				}

				const rel = path.relative(repoRoot, abs).split(path.sep).join('/');
				const kind = fs.statSync(abs).isDirectory() ? 'tree' : 'blob';
				ctx.setProperty(node, 'url', `${repoUrl}/${kind}/${repoBranch}/${rel}${suffix}`);
			},
		};
	};
}
