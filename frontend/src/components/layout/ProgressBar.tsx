/** Thin bar at the very top while a screen is downloading (Filament SPA style). */
export function ProgressBar() {
  return (
    <div role="progressbar" aria-label="Loading" className="fixed inset-x-0 top-0 z-[100] h-0.5 overflow-hidden">
      <div className="h-full w-full origin-left animate-progress bg-accent motion-reduce:animate-none" />
    </div>
  )
}
