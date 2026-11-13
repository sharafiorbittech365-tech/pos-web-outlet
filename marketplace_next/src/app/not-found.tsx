import Link from 'next/link';
import { Ic } from '@/components/Ic';

/** 404 inside the market shell. */
export default function NotFound() {
  return (
    <div className="wrap page">
      <div className="empty">
        <Ic name="search-x" className="big" />
        <b>Not found</b>
        <span>This page, shop or listing is not on the market (any more).</span>
        <Link className="btn primary" href="/">
          Browse the market
        </Link>
      </div>
    </div>
  );
}
