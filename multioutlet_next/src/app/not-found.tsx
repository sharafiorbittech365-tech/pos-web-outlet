import { SiteErrorPage } from '@/components/SiteErrorPage';
import { getContext } from '@/lib/context';

/** 404 inside the website shell (the layout already guarantees the website loaded). */
export default async function NotFound() {
  const { site } = await getContext();
  return <SiteErrorPage kind="not_found" site={site} />;
}
