import type { Metadata } from 'next';
import Link from 'next/link';
import { Sym } from '@/components/Icon';
import { Sh2 } from '@/components/Section';
import { getContext } from '@/lib/context';
import { dur, variantNames } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { CatalogService } from '@/lib/services/catalog';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Services & prices', path: '/services' });
}

export default async function ServicesPage() {
  const { store, api } = await getContext();
  const catalog = new CatalogService(api, store);
  const groups = catalog.byCategory(await catalog.all());

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>Services &amp; prices</span>
      </nav>
      <Sh2 eyebrow="Price list" title={<>All <span className="acc">Services</span></>} icon="tag" />
      <div className="svc-cols">
        {groups.map((g) => (
          <div className="svc-grp" key={String(g.category.id)}>
            <h3>
              <Sym name={g.category.icon} /> {String(g.category.name)}
            </h3>
            {g.items.map((p) => {
              const vn = variantNames(p);
              return (
                <div className="svc" key={String(p.id)}>
                  <div className="grow">
                    <b>{String(p.name)}</b>
                    <small>
                      {p.duration_min ? dur(Number(p.duration_min)) : ''}
                      {vn.length > 0 ? ' · ' + vn.join(' / ') : ''}
                    </small>
                  </div>
                  <span className="pr">
                    {vn.length > 0 ? 'from ' : ''}
                    {store.money(p.price)}
                  </span>
                  {store.canBook() && (
                    <Link className="svc-b" href={`/book?service=${Number(p.id)}`}>
                      Book
                    </Link>
                  )}
                </div>
              );
            })}
          </div>
        ))}
      </div>
      {groups.length === 0 && <div className="empty-s">Our price list is being updated.</div>}
    </section>
  );
}
