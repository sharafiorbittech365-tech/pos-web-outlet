import { StoreErrorPage } from '@/components/StoreErrorPage';
import { getContext } from '@/lib/context';

/** 404 inside the shop shell (the layout already guarantees the shop loaded). */
export default async function NotFound() {
  const { store } = await getContext();
  return <StoreErrorPage kind="not_found" store={store} />;
}
