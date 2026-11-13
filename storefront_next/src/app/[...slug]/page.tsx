import { notFound } from 'next/navigation';

/** Any path no other route handles → the shop's own 404 page (the PHP "(:any)" fallback route). */
export default function CatchAll(): never {
  notFound();
}
