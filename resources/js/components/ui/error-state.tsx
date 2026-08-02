import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * The failure twin of `EmptyState`, slot for slot, so a surface can swap
 * between the two without relaying out.
 *
 * `role="alert"` is on the root rather than the title: the whole block is the
 * announcement, and splitting it makes screen readers read the description as
 * unrelated prose after the fact.
 */
function ErrorState({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="error-state"
      role="alert"
      className={cn(
        "flex flex-col items-center justify-center rounded-xl border border-dashed border-destructive/40 px-6 py-12 text-center",
        className
      )}
      {...props}
    />
  )
}

function ErrorStateIcon({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      aria-hidden="true"
      data-slot="error-state-icon"
      className={cn(
        "mb-4 flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive [&_svg]:size-6 [&_svg]:shrink-0",
        className
      )}
      {...props}
    />
  )
}

function ErrorStateTitle({ className, ...props }: React.ComponentProps<"p">) {
  return (
    <p
      data-slot="error-state-title"
      className={cn("text-base font-semibold", className)}
      {...props}
    />
  )
}

function ErrorStateDescription({
  className,
  ...props
}: React.ComponentProps<"p">) {
  return (
    <p
      data-slot="error-state-description"
      className={cn(
        "mt-1 max-w-sm text-sm text-balance text-muted-foreground",
        className
      )}
      {...props}
    />
  )
}

function ErrorStateActions({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="error-state-actions"
      className={cn(
        "mt-4 flex flex-wrap items-center justify-center gap-2",
        className
      )}
      {...props}
    />
  )
}

export {
  ErrorState,
  ErrorStateActions,
  ErrorStateDescription,
  ErrorStateIcon,
  ErrorStateTitle,
}
