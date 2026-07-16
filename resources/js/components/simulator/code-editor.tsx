import Editor from '@monaco-editor/react';
import type { OnMount } from '@monaco-editor/react';
import type { editor } from 'monaco-editor';
import { useCallback, useEffect, useRef } from 'react';

type CodeEditorProps = {
    value: string;
    onChange: (value: string) => void;
    readOnly?: boolean;
};

export function CodeEditor({ value, onChange, readOnly }: CodeEditorProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const editorRef = useRef<editor.IStandaloneCodeEditor | null>(null);

    const handleMount: OnMount = useCallback((editorInstance) => {
        editorRef.current = editorInstance;
        editorInstance.layout();
    }, []);

    // Monaco's own `automaticLayout` option can miss the container's real size
    // when it first mounts inside a CSS grid/flex layout (or after the sidebar
    // toggles), so re-measure explicitly whenever the container resizes.
    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const observer = new ResizeObserver(() => {
            editorRef.current?.layout();
        });

        observer.observe(container);

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={containerRef} className="h-full w-full">
            <Editor
                height="100%"
                defaultLanguage="javascript"
                theme="vs-dark"
                value={value}
                onMount={handleMount}
                onChange={(next) => onChange(next ?? '')}
                options={{
                    minimap: { enabled: false },
                    fontSize: 13,
                    readOnly,
                    scrollBeyondLastLine: false,
                    tabSize: 2,
                }}
            />
        </div>
    );
}
