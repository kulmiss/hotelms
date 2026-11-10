import { useMediaQuery } from './useMediaQuery'

/** Phones and tablets (below 1024px): filters fold behind one "Filters" button (P19, CollapsibleToolbar). */
export const COMPACT_TOOLBAR_QUERY = '(max-width: 1023px)'
export const useCompactToolbar = () => useMediaQuery(COMPACT_TOOLBAR_QUERY)
