import { defineCollection, z } from 'astro:content';

const ortsteilEnum = z.enum(['borstendorf', 'gruenhainichen', 'waldkirchen']);

const news = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    date: z.date(),
    category: z.enum(['verwaltung', 'veranstaltung', 'sperrung', 'tourismus']),
    ortsteil: z.union([ortsteilEnum, z.literal('alle')]).optional(),
    image: z.string().optional(),
    excerpt: z.string(),
    featured: z.boolean().default(false),
  }),
});

const events = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    startDate: z.date(),
    endDate: z.date().optional(),
    location: z.string(),
    ortsteil: ortsteilEnum.optional(),
    featured: z.boolean().default(false),
    teaser: z.string(),
    image: z.string().optional(),
    ctaUrl: z.string().url().optional(),
    ctaLabel: z.string().optional(),
  }),
});

const ortsteile = defineCollection({
  type: 'content',
  schema: z.object({
    name: z.string(),
    key: ortsteilEnum,
    tagline: z.string(),
    description: z.string(),
    image: z.string().optional(),
    order: z.number().default(0),
  }),
});

export const collections = { news, events, ortsteile };
