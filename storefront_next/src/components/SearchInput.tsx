'use client';
import { useSearchParams } from 'next/navigation';

/** The header search box, pre-filled with the current ?q= (the PHP layout read it server-side). */
export function SearchInput({ placeholder }: { placeholder: string }) {
  const q = useSearchParams().get('q') ?? '';
  return <input key={q} name="q" defaultValue={q} placeholder={placeholder} aria-label="Search" maxLength={100} />;
}
