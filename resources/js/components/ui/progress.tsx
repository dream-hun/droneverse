import * as React from "react"

import { cn } from "@/lib/utils"

type ProgressProps = React.ComponentProps<"div"> & {
  /** Current value. Omit (or pass null) for an indeterminate bar. */
  value?: number | null
  /** Upper bound of the range. Must be positive; falls back to 100. */
  max?: number
  /** Custom accessible text, e.g. `(v, max) => `${v} of ${max} done``. */
  getValueLabel?: (value: number, max: number) => string
}

function Progress({
  className,
  value,
  max = 100,
  getValueLabel,
  ...props
}: ProgressProps) {
  const safeMax = Number.isFinite(max) && max > 0 ? max : 100
  const isDeterminate = typeof value === "number" && Number.isFinite(value)
  const safeValue = isDeterminate ? Math.min(Math.max(value, 0), safeMax) : null
  const percentage = safeValue === null ? null : (safeValue / safeMax) * 100
  const state =
    safeValue === null
      ? "indeterminate"
      : safeValue >= safeMax
        ? "complete"
        : "loading"

  return (
    <div
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={safeMax}
      aria-valuenow={safeValue ?? undefined}
      aria-valuetext={
        safeValue !== null && getValueLabel
          ? getValueLabel(safeValue, safeMax)
          : undefined
      }
      data-slot="progress"
      data-state={state}
      className={cn(
        "bg-primary/15 relative h-2 w-full overflow-hidden rounded-full",
        className
      )}
      {...props}
    >
      <div
        data-slot="progress-indicator"
        data-state={state}
        className={cn(
          "bg-primary h-full rounded-full transition-transform duration-500",
          percentage === null
            ? "animate-progress-indeterminate w-1/3 motion-reduce:w-full motion-reduce:animate-pulse"
            : "w-full"
        )}
        style={
          percentage === null
            ? undefined
            : { transform: `translateX(-${100 - percentage}%)` }
        }
      />
    </div>
  )
}

export { Progress }
