'use client';
/** The booking form (Booking/Views/book.php). Service / stylist / date are plain links; the rest posts to createBooking. */
import Link from 'next/link';
import { useActionState, useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { createBooking } from '@/app/actions/booking';
import { Icon } from '@/components/Icon';
import { refreshHumanCheck } from '@/components/HumanCheck';
import { useToast } from '@/components/Toast';
import { emptyForm } from '@/lib/form-errors';
import { initials, sfUrl } from '@/lib/format';

export interface BookingData {
  kind: string;
  cta: string;
  storeName: string;
  agency: boolean;
  takesFiles: boolean;
  date: string;
  dateLabel: string;
  today: string;
  dates: { v: string; day: string; num: string; mon: string }[];
  services: { id: number; name: string; durMin: number; durText: string; price: string }[];
  allNames: string[];
  cake: boolean;
  service: number;
  staff: string;
  team: string[];
  slots: { time: string; starts_at: string }[];
  at: string;
  party: number;
  hours: number;
  idem: string;
}

const TIMED = ['appointment', 'room', 'table', 'pickup', 'visit', 'repair'];
const FieldErr = ({ e }: { e?: string }) => (e ? <small className="f-err">{e}</small> : null);

export function BookingForm({ data: d, shield }: { data: BookingData; shield: ReactNode }) {
  const [state, action, pending] = useActionState(createBooking, emptyForm);
  const toast = useToast();
  const { kind } = d;
  const v = state.values;
  const err = state.errors;
  const o = (k: string, def = ''): string => v[k] ?? def;
  const timed = TIMED.includes(kind);

  // radio selections are state so the ".on" class follows the checked radio (the old storefront-app.js sync)
  const [at, setAt] = useState(d.at);
  const [party, setParty] = useState(d.party);
  useEffect(() => {
    if (state.n === 0) return;
    if (v.starts_at !== undefined) setAt(v.starts_at);
    if (v.party !== undefined) setParty(Math.trunc(Number(v.party)) || d.party);
    if (state.error) toast(state.error, false);
    refreshHumanCheck();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.n]);

  const keep = { date: d.date, service: d.service || null, staff: d.staff !== 'any' ? d.staff : null, party: ['room', 'table'].includes(kind) ? d.party : null };
  const link = (change: Record<string, string | number | null>): string => sfUrl('/book', { ...keep, ...change });
  const chosen = d.services.find((p) => p.id === d.service) ?? null;
  const picked = d.slots.find((s) => s.starts_at === at) ?? null;
  let n = 0;

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>{d.cta}</span>
      </nav>
      <h1>{d.cta}</h1>
      <form className="co" action={action} data-book>
        <input type="hidden" name="date" value={d.date} />
        <input type="hidden" name="service" value={d.service || ''} />
        <input type="hidden" name="staff" value={d.staff} />
        <input type="hidden" name="_idem" value={d.idem} />
        <input type="hidden" name="has_services" value={kind === 'appointment' && d.services.length > 0 ? '1' : '0'} />
        <div className="co-main" key={state.n}>
          {kind === 'appointment' && d.services.length > 0 && (
            <>
              <div className="box">
                <h3>
                  <span>{++n}</span>Choose a service
                </h3>
                <div className="svc-pick">
                  {d.services.map((p) => {
                    const on = p.id === d.service;
                    return (
                      <Link key={p.id} className={'sp ' + (on ? 'on' : '')} href={link({ service: on ? null : p.id })} aria-current={on ? 'true' : undefined}>
                        <span className="sp-c">{on ? <Icon name="check" /> : null}</span>
                        <span className="grow">
                          <b>{p.name}</b>
                          <small>{p.durText}</small>
                        </span>
                        <span className="pr">{p.price}</span>
                      </Link>
                    );
                  })}
                </div>
                <FieldErr e={err.service} />
                {chosen && (
                  <p className="bk-sum">
                    {chosen.name} · {chosen.durText || '30 min'} · <b>{chosen.price}</b> <small className="muted">(pay at the salon)</small>
                  </p>
                )}
              </div>
              {d.team.length > 0 && (
                <div className="box">
                  <h3>
                    <span>{++n}</span>Choose your stylist
                  </h3>
                  <div className="staff-pick">
                    <Link className={'stp ' + (d.staff === 'any' ? 'on' : '')} href={link({ staff: null })}>
                      <span className="av-s">
                        <Icon name="users" />
                      </span>
                      <b>Any stylist</b>
                      <small>Fastest slot</small>
                    </Link>
                    {d.team.map((s, i) => (
                      <Link key={s} className={'stp ' + (d.staff === s ? 'on' : '')} href={link({ staff: s })}>
                        <span className="av-s" style={{ '--h': i * 67 } as CSSProperties}>
                          {initials(s)}
                        </span>
                        <b>{s}</b>
                      </Link>
                    ))}
                  </div>
                </div>
              )}
            </>
          )}

          {kind === 'room' && (
            <div className="box">
              <h3>
                <span>{++n}</span>Room &amp; duration
              </h3>
              {d.services.length > 0 && (
                <div className="staff-pick">
                  <Link className={'stp ' + (d.service === 0 ? 'on' : '')} href={link({ service: null })}>
                    <span className="av-s">
                      <Icon name="mic" />
                    </span>
                    <b>Any room</b>
                    <small>that fits</small>
                  </Link>
                  {d.services.map((p) => (
                    <Link key={p.id} className={'stp ' + (p.id === d.service ? 'on' : '')} href={link({ service: p.id })}>
                      <span className="av-s">
                        <Icon name="mic" />
                      </span>
                      <b>{p.name}</b>
                      <small>{p.price}</small>
                    </Link>
                  ))}
                </div>
              )}
              <div className="fg">
                <label>
                  Hours
                  <select name="hours" defaultValue={String(Math.trunc(Number(o('hours', String(d.hours)))) || d.hours)}>
                    {Array.from({ length: 6 }, (_, i) => i + 1).map((x) => (
                      <option key={x}>{x}</option>
                    ))}
                  </select>
                </label>
                <label>
                  People
                  <select name="party" defaultValue={String(party)}>
                    {Array.from({ length: 20 }, (_, i) => i + 1).map((x) => (
                      <option key={x}>{x}</option>
                    ))}
                  </select>
                  <FieldErr e={err.party} />
                </label>
              </div>
            </div>
          )}

          {kind === 'table' && (
            <div className="box">
              <h3>
                <span>{++n}</span>Party size
              </h3>
              <div className="party">
                {[1, 2, 3, 4, 5, 6, 8, 10].map((x) => (
                  <label key={x} className={'pchip ' + (party === x ? 'on' : '')}>
                    <input type="radio" name="party" value={x} checked={party === x} onChange={() => setParty(x)} />
                    {x}
                    {x === 10 ? '+' : ''}
                  </label>
                ))}
              </div>
              <FieldErr e={err.party} />
            </div>
          )}

          {kind === 'quote' && (
            <div className="box">
              <h3>
                <span>{++n}</span>
                {d.cta}
              </h3>
              <div className="fg">
                <label className="span2">
                  {d.agency ? 'What do you need?' : 'Describe the job'} *
                  <textarea
                    name="details"
                    rows={4}
                    required
                    maxLength={2000}
                    placeholder={d.agency ? 'e.g. New website with online payments, plus monthly social media' : d.cake ? 'e.g. 2-pound chocolate cake, "Happy Birthday Ayaan", blue theme' : 'e.g. 1,000 business cards, 2 sides, matte lamination'}
                    defaultValue={o('details', chosen ? 'Interested in: ' + chosen.name : '')}
                  />
                  <FieldErr e={err.details} />
                </label>
                <label>
                  {d.agency ? 'Budget (optional)' : 'Quantity / size'}
                  <input name="budget" maxLength={120} defaultValue={o('budget')} />
                </label>
                <label>
                  Needed by
                  <input name="due" type="date" min={d.today} defaultValue={o('due')} />
                  <FieldErr e={err.due} />
                </label>
                {d.takesFiles && (
                  <>
                    <label className="span2">
                      Link to your design / brief (optional)
                      <input name="file_link" type="url" maxLength={500} placeholder="https://drive.google.com/…" defaultValue={o('file_link')} />
                      <FieldErr e={err.file_link} />
                    </label>
                    <p className="span2 up-note">
                      <Icon name="file" />
                      <span>Have a file instead? Paste a share link above, or send it to us after we reply — we will send you a price and a proof before printing.</span>
                    </p>
                  </>
                )}
              </div>
            </div>
          )}

          {kind === 'repair' && (
            <div className="box">
              <h3>
                <span>{++n}</span>Your device
              </h3>
              <div className="fg">
                <label>
                  Device *
                  <input name="device" required maxLength={120} placeholder="e.g. iPhone 13, HP 15s laptop" defaultValue={o('device')} />
                  <FieldErr e={err.device} />
                </label>
                <label className="span2">
                  What is wrong? *
                  <textarea name="details" rows={3} required maxLength={2000} placeholder="e.g. screen cracked, battery drains fast" defaultValue={o('details')} />
                  <FieldErr e={err.details} />
                </label>
              </div>
            </div>
          )}

          {kind === 'visit' && (
            <div className="box">
              <h3>
                <span>{++n}</span>What would you like to see?
              </h3>
              <div className="fg">
                <label className="span2">
                  Interested in
                  <select name="interest" defaultValue={o('interest', chosen?.name ?? '')}>
                    <option value="">Just browsing</option>
                    {d.allNames.map((nm, i) => (
                      <option key={i}>{nm}</option>
                    ))}
                  </select>
                </label>
              </div>
            </div>
          )}

          {kind === 'pickup' && (
            <div className="box">
              <h3>
                <span>{++n}</span>Pickup address
              </h3>
              <div className="fg">
                <label className="span2">
                  Address *
                  <input name="address" required maxLength={300} placeholder="House, road, area" autoComplete="street-address" defaultValue={o('address')} />
                  <FieldErr e={err.address} />
                </label>
                <label className="span2">
                  Items (optional)
                  <textarea name="details" rows={2} maxLength={2000} placeholder="e.g. 4 shirts, 2 trousers, 1 suit — express" defaultValue={o('details')} />
                </label>
              </div>
            </div>
          )}

          {timed && (
            <div className="box" id="when">
              <h3>
                <span>{++n}</span>
                {kind === 'pickup' ? 'Pickup day & time' : 'Date & time'}
              </h3>
              <div className="dchips">
                {d.dates.map((x) => (
                  <Link key={x.v} className={'dchip ' + (x.v === d.date ? 'on' : '')} href={link({ date: x.v }) + '#when'}>
                    <small>{x.day}</small>
                    <b>{x.num}</b>
                    <small>{x.mon}</small>
                  </Link>
                ))}
              </div>
              {d.slots.length === 0 ? (
                <p className="muted">No free time on this day — please choose another date.</p>
              ) : (
                <div className={'slots ' + (kind === 'pickup' ? 'wins' : '')}>
                  {d.slots.map((s) => (
                    <label key={s.starts_at} className={'slot ' + (s.starts_at === at ? 'on' : '')}>
                      <input type="radio" name="starts_at" value={s.starts_at} checked={s.starts_at === at} onChange={() => setAt(s.starts_at)} required />
                      {s.time}
                    </label>
                  ))}
                </div>
              )}
              <FieldErr e={err.time} />
            </div>
          )}

          <div className="box">
            <h3>
              <span>{++n}</span>Your details
            </h3>
            <div className="fg">
              <label>
                Full name *
                <input name="name" required maxLength={100} autoComplete="name" defaultValue={o('name')} />
                <FieldErr e={err.name} />
              </label>
              <label>
                Mobile number *
                <input name="phone" required inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" maxLength={20} defaultValue={o('phone')} />
                <FieldErr e={err.phone} />
              </label>
              <label>
                Email (optional)
                <input name="email" type="email" maxLength={150} autoComplete="email" defaultValue={o('email')} />
                <FieldErr e={err.email} />
              </label>
              {kind !== 'pickup' && (
                <label>
                  Note (optional)
                  <input name="note" maxLength={500} defaultValue={o('note')} placeholder={kind === 'table' ? 'e.g. birthday, window seat' : 'Anything we should know'} />
                </label>
              )}
            </div>
          </div>
        </div>
        <aside className="co-sum">
          <h3>Summary</h3>
          <div className="r it">
            <span>
              <Icon name="store" />
              {d.storeName}
            </span>
          </div>
          <div className="r it">
            <span>
              <Icon name="cal" />
              {kind === 'quote' ? 'We reply with a price, usually the same day.' : d.dateLabel + (picked ? ' · ' + picked.time : ' · pick a time')}
            </span>
          </div>
          {d.staff !== 'any' && kind === 'appointment' && (
            <div className="r it">
              <span>
                <Icon name="user" />
                {d.staff}
              </span>
            </div>
          )}
          {['table', 'room'].includes(kind) && (
            <div className="r it">
              <span>
                <Icon name="users" />
                {party} people{kind === 'room' ? ' · ' + d.hours + ' h' : ''}
              </span>
            </div>
          )}
          {chosen && kind === 'appointment' && (
            <div className="r tot">
              <span>Estimated</span>
              <b>{chosen.price}</b>
            </div>
          )}
          {shield}
          <button className="btn-p full" type="submit" data-once disabled={pending || (timed && d.slots.length === 0)}>
            <Icon name="check" />
            {kind === 'quote' ? 'Send request' : 'Confirm booking'}
          </button>
          <small className="muted">No payment now. You get a confirmation call or SMS.</small>
        </aside>
      </form>
    </section>
  );
}
