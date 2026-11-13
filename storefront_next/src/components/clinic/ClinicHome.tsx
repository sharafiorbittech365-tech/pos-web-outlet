/** CLINIC template home (Clinic/Views/home.php): hero, doctors, about. */
import Link from 'next/link';
import { AboutBlock } from '@/components/Blocks';
import { Icon, Pic } from '@/components/Icon';
import { Accent, Sh2, ViewAll } from '@/components/Section';
import { accentSplit } from '@/lib/format';
import { type Store } from '@/lib/store';
import { DoctorCard } from './DoctorCard';

export function ClinicHome({ store, doctors }: { store: Store; doctors: Record<string, any>[] }) {
  const brand = store.brand();
  const phone = store.phone();
  const [lead, tail] = accentSplit(String(brand.hero || store.name()));
  return (
    <>
      <section className="bk-hero">
        <Pic i={0} brand={brand} className="bh-img" alt={store.name()} />
        <div className="wrap bh-in">
          <div className="bh-t">
            <span className="eyebrow">{store.typeTag()}</span>
            <h1 className="cond">
              <Accent lead={lead} tail={tail} />
            </h1>
            <p>{String(brand.sub ?? '')}</p>
            <div className="hero-cta">
              <Link className="btn-p" href="/doctors">
                Book appointment <Icon name="cal" />
              </Link>
              {phone !== '' && (
                <a className="btn-g" href={`tel:${phone}`}>
                  <Icon name="phone" />
                  {phone}
                </a>
              )}
            </div>
          </div>
          <div className="bh-card">
            <b>Our doctors</b>
            {doctors.length === 0 ? (
              <p className="muted">Call us to book.</p>
            ) : (
              <div className="cl-mini">
                {doctors.slice(0, 4).map((d) => (
                  <Link key={String(d.id)} className="r it" href={`/doctors/${Math.trunc(Number(d.id))}`}>
                    <span>
                      <Icon name="stethoscope" />
                      {String(d.name)}
                      {d.specialty ? ' · ' + String(d.specialty) : ''}
                    </span>
                  </Link>
                ))}
              </div>
            )}
            <Link className="btn-p full" href="/doctors">
              See free times <Icon name="arrow" />
            </Link>
          </div>
        </div>
      </section>

      <section className="wrap sec" id="sec-doctors">
        <Sh2 eyebrow="Book an appointment" title={<Accent lead="Our" tail="Doctors" />} icon="stethoscope" more={doctors.length > 0 ? <ViewAll href="/doctors" label="All doctors" /> : undefined} />
        {doctors.length === 0 ? (
          <div className="dr-empty">Online appointments are not open right now{phone !== '' ? ' — please call ' + phone : ''}.</div>
        ) : (
          <div className="dr-grid">
            {doctors.slice(0, 6).map((d) => (
              <DoctorCard key={String(d.id)} store={store} d={d} />
            ))}
          </div>
        )}
      </section>

      {store.section('about') && (
        <AboutBlock
          store={store}
          eyebrow="About us"
          title={store.name()}
          text={String(store.site.seo?.description ?? '')}
          stats={[]}
          button={
            <Link className="btn-p" href="/doctors">
              Book appointment <Icon name="arrow" />
            </Link>
          }
        />
      )}
    </>
  );
}
