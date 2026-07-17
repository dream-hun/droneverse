import * as React from "react"

import { cn } from "@/lib/utils"

function EmptyState({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="empty-state"
      className={cn(
        "flex flex-col items-center justify-center rounded-xl border border-dashed px-6 py-12 text-center",
        className
      )}
      {...props}
    />
  )
}

function EmptyStateIcon({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      aria-hidden="true"
      data-slot="empty-state-icon"
      className={cn(
        "bg-muted text-muted-foreground mb-4 flex size-12 items-center justify-center rounded-full [&_svg]:size-6 [&_svg]:shrink-0",
        className
      )}
      {...props}
    />
  )
}

function EmptyStateTitle({ className, ...props }: React.ComponentProps<"p">) {
  return (
    <p
      data-slot="empty-state-title"
      className={cn("text-base font-semibold", className)}
      {...props}
    />
  )
}

function EmptyStateDescription({
  className,
  ...props
}: React.ComponentProps<"p">) {
  return (
    <p
      data-slot="empty-state-description"
      className={cn(
        "text-muted-foreground mt-1 max-w-sm text-sm text-balance",
        className
      )}
      {...props}
    />
  )
}

function EmptyStateActions({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="empty-state-actions"
      className={cn(
        "mt-4 flex flex-wrap items-center justify-center gap-2",
        className
      )}
      {...props}
    />
  )
}

export {
  EmptyState,
  EmptyStateActions,
  EmptyStateDescription,
  EmptyStateIcon,
  EmptyStateTitle,
}
