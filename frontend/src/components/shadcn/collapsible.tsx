import { createContext, useContext, useId, type ComponentProps, type MouseEvent } from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cn } from '@/lib/cn'

/*
 * The template's Collapsible (Collapsible, CollapsibleTrigger, CollapsibleContent; same names, `open`,
 * `onOpenChange`, `asChild` and `data-state`). The template wraps @radix-ui/react-collapsible; that package is
 * not installed here (QUESTIONS.md P19-1), so this small copy does the same job without a new package.
 *
 * The content stays in the page while closed (the filter boxes keep what was typed), but it is `inert`: no tab
 * stop and hidden from screen readers. It opens and closes with a 200ms height change (none with reduced motion).
 */
type Ctx = { open: boolean; setOpen: (open: boolean) => void; contentId: string }
const CollapsibleContext = createContext<Ctx | null>(null)

const useCollapsible = () => {
  const ctx = useContext(CollapsibleContext)
  if (!ctx) throw new Error('Collapsible parts must be inside <Collapsible>')
  return ctx
}

type RootProps = ComponentProps<'div'> & { open: boolean; onOpenChange: (open: boolean) => void }

function Collapsible({ open, onOpenChange, className, ...props }: RootProps) {
  const contentId = useId()
  return (
    <CollapsibleContext.Provider value={{ open, setOpen: onOpenChange, contentId }}>
      <div data-slot="collapsible" data-state={open ? 'open' : 'closed'} className={className} {...props} />
    </CollapsibleContext.Provider>
  )
}

function CollapsibleTrigger({ asChild, onClick, ...props }: ComponentProps<'button'> & { asChild?: boolean }) {
  const { open, setOpen, contentId } = useCollapsible()
  const Comp = asChild ? Slot : 'button'
  return (
    <Comp
      type="button"
      data-slot="collapsible-trigger"
      data-state={open ? 'open' : 'closed'}
      aria-expanded={open}
      aria-controls={contentId}
      onClick={(e: MouseEvent<HTMLButtonElement>) => {
        onClick?.(e)
        if (!e.defaultPrevented) setOpen(!open)
      }}
      {...props}
    />
  )
}

function CollapsibleContent({ className, children, ...props }: ComponentProps<'div'>) {
  const { open, contentId } = useCollapsible()
  return (
    <div
      id={contentId}
      data-slot="collapsible-content"
      data-state={open ? 'open' : 'closed'}
      inert={!open}
      className={cn('grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none', open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}
      {...props}
    >
      {/* Room for focus rings inside the clipped box. */}
      <div className={cn('-mx-1 min-h-0 overflow-hidden', open && '-my-1')}>
        <div className={cn('p-1', className)}>{children}</div>
      </div>
    </div>
  )
}

export { Collapsible, CollapsibleTrigger, CollapsibleContent }
