import { notFound } from 'next/navigation';

/** Any other path: the website's own 404 page (inside the shell). */
export default function CatchAll() {
  notFound();
}
