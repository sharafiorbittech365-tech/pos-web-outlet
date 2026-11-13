/** Per-request facts shared by the layout and pages: the market meta, the signed-in buyer, the current path. */
import 'server-only';
import { cache } from 'react';
import { headers } from 'next/headers';
import { isApiError } from './api/errors';
import { marketApi, type Json } from './api/market-api';
import { config } from './config';
import { getBuyerToken, relative } from './visitor';

/** radius chips, areas, shop count … (cached for 5 minutes by the API client). */
export const getMeta = cache(async (): Promise<Json> => {
  try {
    return await (await marketApi()).meta();
  } catch (e) {
    if (!isApiError(e)) throw e;
    // the header must still render: no areas list until the API is back
    return { brand: config.brandName, radius_chips: [2, 5, 10, 25, 0], default_radius_km: 10, areas: [], shop_count: 0, pos_url: '' };
  }
});

/** The signed-in buyer, or null (no token, expired token, removed account). */
export const getBuyer = cache(async (): Promise<Json | null> => {
  if ((await getBuyerToken()) === '') return null;
  try {
    return await (await marketApi()).me();
  } catch (e) {
    if (isApiError(e) && (e.status === 401 || e.status === 0 || e.status >= 500)) return null;
    throw e;
  }
});

/** The current path + query (set by src/proxy.ts). */
export const currentPath = async (): Promise<string> => relative((await headers()).get('x-mk-path'));
