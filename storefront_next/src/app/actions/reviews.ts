'use server';
/** Product review from the product page: POST /v1/products/{id}/reviews (waits for the shop's approval). */
import { randomBytes } from 'node:crypto';
import { revalidatePath } from 'next/cache';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { failed, fromApi, type FormState } from '@/lib/form-errors';
import { formToObject } from '@/lib/request';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';

const MAX_IMAGES = 3;
const MAX_BYTES = 5 * 1024 * 1024;
const TYPES = new Set(['image/png', 'image/jpeg', 'image/webp', 'image/gif']);

export async function submitReview(prev: FormState, fd: FormData): Promise<FormState> {
  const { api } = await getContext();
  const input = formToObject(fd);
  const id = Math.trunc(Number(input.product_id ?? 0));
  const wait = await throttle('post:review', 5, 600);
  if (wait > 0) return failed(prev, input, {}, TOO_MANY(wait));
  const errors: Record<string, string> = {};
  const rating = Math.trunc(Number(input.rating ?? 0));
  if (id < 1) return failed(prev, input, {}, 'That product could not be found.');
  if (!(input.author ?? '').trim()) errors.author = 'Please enter your name.';
  if (rating < 1 || rating > 5) errors.rating = 'Choose 1 to 5 stars.';
  if (!(input.title ?? '').trim() && !(input.body ?? '').trim()) errors.body = 'Write a few words about the product.';

  const images: string[] = [];
  for (const f of fd.getAll('images')) {
    if (!(f instanceof File) || f.size === 0) continue;
    if (images.length >= MAX_IMAGES) {
      errors.images = `You can add up to ${MAX_IMAGES} pictures.`;
      break;
    }
    if (!TYPES.has(f.type) || f.size > MAX_BYTES) {
      errors.images = 'Pictures must be PNG, JPG, WebP or GIF, up to 5 MB each.';
      break;
    }
    images.push(`data:${f.type};base64,${Buffer.from(await f.arrayBuffer()).toString('base64')}`);
  }
  if (Object.keys(errors).length > 0) return failed(prev, input, errors);

  const human = await verifyHuman(input);
  if (human !== null) return failed(prev, input, {}, human);

  try {
    await api.addReview(id, { author: input.author.trim(), rating, title: (input.title ?? '').trim(), body: (input.body ?? '').trim(), images }, 'r' + randomBytes(12).toString('hex'));
  } catch (e) {
    if (isApiError(e) && e.status === 422) return failed(prev, input, fromApi(e.details), e.message);
    throw e;
  }
  revalidatePath('/p/' + id);
  return { errors: {}, error: '', values: { ok: '1' }, n: prev.n + 1 };
}
