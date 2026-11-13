import type { Metadata } from 'next';
import { ClinicHome } from '@/components/clinic/ClinicHome';
import { HomeBooking } from '@/components/home/HomeBooking';
import { HomeMenu } from '@/components/home/HomeMenu';
import { HomeService } from '@/components/home/HomeService';
import { HomeShop } from '@/components/home/HomeShop';
import { getContext } from '@/lib/context';
import { today } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { BookingService } from '@/lib/services/booking';
import { CatalogService, pickFeatured, TABS } from '@/lib/services/catalog';
import { cartCount, priced } from '@/lib/services/cart';
import { ClinicSite } from '@/lib/services/clinic';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { path: '/' });
}

export default async function Home({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const ctx = await getContext();
  const { store, api, host } = ctx;

  if (store.isClinic()) return <ClinicHome store={store} doctors={await new ClinicSite(api).doctors()} />;

  const catalog = new CatalogService(api, store);
  const all = await catalog.all();

  switch (store.kind()) {
    case 'booking': {
      let staff: string[] = [];
      let quick: Awaited<ReturnType<BookingService['slots']>>['slots'] = [];
      let quickLoaded = false;
      if (store.canBook()) {
        const todaySlots = await new BookingService(api, store).slots(today());
        staff = store.staff().length > 0 ? store.staff() : todaySlots.staff;
        quick = todaySlots.slots.slice(0, 6);
        quickLoaded = true;
      }
      return <HomeBooking store={store} all={all} staff={staff} quick={quick} quickLoaded={quickLoaded} />;
    }
    case 'menu': {
      const cart = (await cartCount(host)) > 0 ? await priced(ctx) : null;
      return <HomeMenu store={store} all={all} groups={catalog.byCategory(all)} popular={pickFeatured(all, 4)} cart={cart} />;
    }
    case 'service':
      return <HomeService store={store} groups={catalog.byCategory(all)} />;
    default: {
      const t = (await searchParams).tab;
      const tab = Array.isArray(t) ? t[0] : t;
      return <HomeShop store={store} tabs={TABS} sections={catalog.homeSections(all, tab !== undefined && tab in TABS ? tab : 'featured')} />;
    }
  }
}
