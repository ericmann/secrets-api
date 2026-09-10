import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';
import { docsSchema } from '@astrojs/starlight/schema';

// The docs collection is the repo's docs/ folder, not site/src/content, so the
// Markdown stays readable on GitHub and there is one source of truth.
export const collections = {
	docs: defineCollection({
		loader: glob({ base: '../docs', pattern: '**/[^_]*.{md,mdx}' }),
		schema: docsSchema({
			extend: z.object({
				// Journal entries carry a date; the sidebar sorts on it.
				date: z.coerce.date().optional(),
			}),
		}),
	}),
};
