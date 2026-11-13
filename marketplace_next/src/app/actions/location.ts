'use server';
/** Choose / forget the location (LocationController::save / clear). */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { setFlash } from '@/lib/flash';
import { throttle, TOO_MANY } from '@/lib/shield';
import { forgetLocation, locLabel, relative, saveLocation } from '@/lib/visitor';

export async function saveLocationAction(fd: FormData): Promise<void> {
  const back = relative(String(fd.get('back') ?? '/'));
  const wait = await throttle('post:location', 30, 60);
  if (wait > 0) {
    await setFlash({ error: TOO_MANY(wait) });
    redirect(back);
  }
  const lat = String(fd.get('lat') ?? '').trim();
  const body = lat !== '' ? { lat: Number(lat), lng: Number(String(fd.get('lng') ?? '')) } : { area: String(fd.get('area') ?? '').slice(0, 64) };
  let loc;
  try {
    loc = (await (await marketApi()).resolveLocation(body)).location;
  } catch (e) {
    if (!isApiError(e) || e.status !== 422) throw e;
    await setFlash({ error: e.message });
    redirect(back);
  }
  await saveLocation(loc);
  await setFlash({ success: `Showing what is near ${locLabel(loc)}.` });
  redirect(back);
}

export async function clearLocationAction(): Promise<void> {
  await forgetLocation();
  redirect('/');
}
