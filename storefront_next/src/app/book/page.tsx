/** Booking::index — the booking form for the shop's booking kind. */
import { randomBytes } from 'node:crypto';
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { BookingForm, type BookingData } from '@/components/booking/BookingForm';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { getContext } from '@/lib/context';
import { dur, phpDate, today, validDate } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { BookingService, TIMED } from '@/lib/services/booking';
import { CatalogService } from '@/lib/services/catalog';

type SP = Promise<Record<string, string | string[] | undefined>>;

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  if (!store.canBook()) return pageMeta(store, { title: 'Booking', noindex: true });
  return pageMeta(store, { title: store.cta(), path: '/book' });
}

export default async function BookPage({ searchParams }: { searchParams: SP }) {
  const sp = await searchParams;
  const { store, api } = await getContext();
  if (!store.canBook()) return <Closed phone={store.phone()} />;
  if (store.isClinic()) redirect('/doctors');

  const g = (k: string): string => {
    const v = sp[k];
    return (Array.isArray(v) ? v[0] : v ?? '').trim();
  };
  const svc = new BookingService(api, store);
  const kind = store.bookingKind();

  const date = validDate(g('date')) && g('date') >= today() ? g('date') : today();
  let services: Record<string, any>[] = [];
  let all: Record<string, any>[] = [];
  if (['appointment', 'room', 'quote', 'visit'].includes(kind)) {
    all = await new CatalogService(api, store).all();
    const timed = all.filter((p) => Number(p.duration_min ?? 0) > 0);
    services = kind === 'appointment' || kind === 'room' ? (timed.length > 0 ? timed : all) : all;
  }
  let service = Math.trunc(Number(g('service'))) || 0;
  if (service > 0 && !services.some((p) => Number(p.id) === service)) service = 0;
  let staff = g('staff') !== '' ? Array.from(g('staff')).slice(0, 100).join('') : 'any';
  let slots: Awaited<ReturnType<BookingService['slots']>> = { slots: [], staff: [], duration: 0 };
  if (TIMED.includes(kind)) slots = await svc.slots(date, service > 0 ? service : null, kind === 'appointment' ? staff : null);
  let team: string[] = [];
  if (kind === 'appointment') {
    const own = store.staff();
    team = own.length > 0 ? own : staff === 'any' ? slots.staff : await svc.staff();
  }
  if (staff !== 'any' && !team.includes(staff)) staff = 'any';

  let at = g('at');
  if (at === '' && /^\d{2}:\d{2}$/.test(g('want'))) {
    const hit = slots.slots.find((s) => s.time >= g('want'));
    if (hit) at = hit.starts_at;
  }
  const num = (v: string, d: number): number => Math.trunc(Number(v)) || d;
  const party = Math.max(1, Math.min(50, num(g('party'), 2)));
  const hours = Math.max(1, Math.min(12, num(g('hours'), 2)));

  const data: BookingData = {
    kind,
    cta: store.cta(),
    storeName: store.name(),
    agency: store.isAgency(),
    takesFiles: store.takesFiles(),
    date,
    dateLabel: phpDate('l, j F', date),
    today: today(),
    dates: Array.from({ length: 10 }, (_, i) => {
      const v = today(i);
      return { v, day: i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : phpDate('D', v), num: phpDate('j', v), mon: phpDate('M', v) };
    }),
    services: services.map((p) => ({
      id: Number(p.id),
      name: String(p.name ?? ''),
      durMin: Number(p.duration_min ?? 0) || 0,
      durText: Number(p.duration_min ?? 0) > 0 ? dur(Number(p.duration_min)) : '',
      price: store.money(p.price),
    })),
    allNames: all.map((p) => String(p.name ?? '')),
    cake: all.some((p) => String(p.name ?? '').toLowerCase().includes('cake')),
    service,
    staff,
    team,
    slots: slots.slots.map((s) => ({ time: s.time, starts_at: s.starts_at })),
    at,
    party,
    hours,
    idem: 'b' + randomBytes(12).toString('hex'),
  };
  return <BookingForm data={data} shield={<ShieldFieldsBlock />} />;
}

function Closed({ phone }: { phone: string }) {
  return (
    <section className="wrap sec narrow">
      <div className="empty-s">
        Online booking is not available right now.
        {phone !== '' && (
          <>
            {' '}
            Please call <a href={`tel:${phone}`}>{phone}</a>.
          </>
        )}
      </div>
    </section>
  );
}
