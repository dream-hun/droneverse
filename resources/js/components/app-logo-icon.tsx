import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
        >
            <path
                d="M8 8L16 16M32 8L24 16M8 32L16 24M32 32L24 24"
                stroke="currentColor"
                strokeWidth="3"
                strokeLinecap="round"
            />
            <circle cx="8" cy="8" r="5" fill="currentColor" />
            <circle cx="32" cy="8" r="5" fill="currentColor" />
            <circle cx="8" cy="32" r="5" fill="currentColor" />
            <circle cx="32" cy="32" r="5" fill="currentColor" />
            <rect
                x="14"
                y="14"
                width="12"
                height="12"
                rx="3"
                fill="currentColor"
            />
        </svg>
    );
}
