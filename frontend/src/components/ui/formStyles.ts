/*
 * The template's form look (shadcn Input / Label / Select trigger), for our own fields and for the few raw
 * inputs on screens. 36px with a mouse, 44px on touch screens (min-h-touch).
 */
export const labelClass = 'flex items-center gap-2 text-sm leading-none font-medium select-none'

export const inputClass =
  'min-h-touch w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base text-text shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted focus-visible:border-focus focus-visible:ring-[3px] focus-visible:ring-focus/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40'

/** Native select styled like the shadcn select trigger (the arrow is drawn by Select.tsx). */
export const selectClass =
  'min-h-touch w-full min-w-0 appearance-none rounded-md border border-input bg-transparent ps-3 pe-8 text-base text-text shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-focus focus-visible:ring-[3px] focus-visible:ring-focus/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:[&>option]:bg-surface'

export const hintClass = 'text-sm text-muted'
export const errorClass = 'text-sm text-danger-text'
