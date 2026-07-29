import { defineCollection, z } from 'astro:content';

const news = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    date: z.date(),
    category: z.enum(['bekanntmachung', 'veranstaltung', 'sperrung', 'ausschreibung']),
    image: z.string().optional(),
    excerpt: z.string(),
    featured: z.boolean().default(false),
    // Optional, nur für category === 'sperrung' relevant
    affectedStreet: z.string().optional(),
    detour: z.string().optional(),
    validUntil: z.date().optional(),
    severity: z.enum(['info', 'warn', 'alert']).optional(),
  }),
});

const events = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    startDate: z.date(),
    endDate: z.date().optional(),
    location: z.string(),
    featured: z.boolean().default(false),
    teaser: z.string(),
    image: z.string().optional(),
  }),
});

export const collections = { news, events };
