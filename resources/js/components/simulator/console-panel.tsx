import { useSimulatorLogs } from '@/lib/simulator/session';
import type { SimulatorSession } from '@/lib/simulator/session';
import { cn } from '@/lib/utils';

/**
 * Subscribes to the session's log slice directly so high-frequency console
 * output re-renders only this panel, not the page that owns the editor and
 * canvas.
 */
export function ConsolePanel({ session }: { session: SimulatorSession }) {
    const logs = useSimulatorLogs(session);

    return (
        <div className="h-full overflow-y-auto rounded-md bg-black/90 p-3 font-mono text-xs text-slate-200">
            {logs.length === 0 && (
                <p className="text-slate-500">
                    Console output will appear here when you run your code.
                </p>
            )}
            {logs.map((line) => (
                <div
                    key={line.id}
                    className={cn(
                        line.level === 'error' && 'text-red-400',
                        line.level === 'warn' && 'text-yellow-400',
                    )}
                >
                    {line.text}
                </div>
            ))}
        </div>
    );
}
