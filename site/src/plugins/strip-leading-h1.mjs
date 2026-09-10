/**
 * Sätteri mdast plugin: drop a leading `# Heading` from a page body.
 *
 * Starlight renders the frontmatter `title` as the page's h1, so a Markdown file
 * that also opens with its own h1 (as the GitHub-readable docs do) would show the
 * title twice. Only a depth-1 heading that is the first block is removed.
 */
export const stripLeadingH1 = {
	name: 'strip-leading-h1',
	before(root, ctx) {
		const first = root.children.find((n) => n.type !== 'yaml');
		if (first && first.type === 'heading' && first.depth === 1) {
			ctx.removeNode(first);
		}
	},
};
