import { CanvasTexture, RepeatWrapping, SRGBColorSpace } from 'three';

/**
 * Procedural textures for the flying field, generated on a canvas at mount
 * time so the simulator ships no image assets. Only called from components
 * rendered inside the R3F <Canvas>, which never runs during SSR.
 */

function createCanvas(
    size: number,
): [HTMLCanvasElement, CanvasRenderingContext2D] {
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const context = canvas.getContext('2d');

    if (!context) {
        throw new Error('2D canvas is unavailable');
    }

    return [canvas, context];
}

function finishTexture(canvas: HTMLCanvasElement): CanvasTexture {
    const texture = new CanvasTexture(canvas);
    texture.colorSpace = SRGBColorSpace;
    texture.anisotropy = 8;

    return texture;
}

function speckle(
    context: CanvasRenderingContext2D,
    size: number,
    colors: string[],
    count: number,
    maxDot: number,
): void {
    for (let i = 0; i < count; i++) {
        context.fillStyle = colors[i % colors.length];
        context.globalAlpha = 0.03 + Math.random() * 0.05;
        context.fillRect(
            Math.random() * size,
            Math.random() * size,
            1 + Math.random() * maxDot,
            1 + Math.random() * maxDot,
        );
    }

    context.globalAlpha = 1;
}

/**
 * Asphalt flying field with a painted 1 m survey grid (bright every 5 m),
 * sky-blue center axes, and a yellow boundary line. The grid doubles as a
 * distance reference for the meters used in drone commands.
 */
export function createFieldTexture(
    widthMeters: number,
    depthMeters: number,
): CanvasTexture {
    const pixelsPerMeter = Math.min(
        24,
        2048 / Math.max(widthMeters, depthMeters),
    );
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(widthMeters * pixelsPerMeter);
    canvas.height = Math.round(depthMeters * pixelsPerMeter);

    const context = canvas.getContext('2d');

    if (!context) {
        throw new Error('2D canvas is unavailable');
    }

    context.fillStyle = '#474d55';
    context.fillRect(0, 0, canvas.width, canvas.height);

    for (let i = 0; i < (canvas.width * canvas.height) / 40; i++) {
        context.fillStyle = Math.random() > 0.5 ? '#ffffff' : '#000000';
        context.globalAlpha = 0.02 + Math.random() * 0.045;
        const dot = 1 + Math.random() * 2;
        context.fillRect(
            Math.random() * canvas.width,
            Math.random() * canvas.height,
            dot,
            dot,
        );
    }

    context.globalAlpha = 1;

    const drawGridLines = (
        stepMeters: number,
        style: string,
        width: number,
    ) => {
        context.strokeStyle = style;
        context.lineWidth = width;

        for (let x = 0; x <= widthMeters; x += stepMeters) {
            const px = Math.round(x * pixelsPerMeter) + 0.5;
            context.beginPath();
            context.moveTo(px, 0);
            context.lineTo(px, canvas.height);
            context.stroke();
        }

        for (let z = 0; z <= depthMeters; z += stepMeters) {
            const pz = Math.round(z * pixelsPerMeter) + 0.5;
            context.beginPath();
            context.moveTo(0, pz);
            context.lineTo(canvas.width, pz);
            context.stroke();
        }
    };

    drawGridLines(1, 'rgba(255, 255, 255, 0.05)', 1);
    drawGridLines(5, 'rgba(255, 255, 255, 0.11)', 2);

    // Center axes through the origin, in the app's sky accent.
    context.strokeStyle = 'rgba(56, 189, 248, 0.22)';
    context.lineWidth = 2;
    context.beginPath();
    context.moveTo(canvas.width / 2 + 0.5, 0);
    context.lineTo(canvas.width / 2 + 0.5, canvas.height);
    context.moveTo(0, canvas.height / 2 + 0.5);
    context.lineTo(canvas.width, canvas.height / 2 + 0.5);
    context.stroke();

    // Field boundary.
    context.strokeStyle = 'rgba(250, 204, 21, 0.4)';
    context.lineWidth = 4;
    context.strokeRect(2, 2, canvas.width - 4, canvas.height - 4);

    return finishTexture(canvas);
}

/** Mottled grass for the terrain surrounding the field. */
export function createGrassTexture(): CanvasTexture {
    const size = 256;
    const [canvas, context] = createCanvas(size);

    context.fillStyle = '#5a6c42';
    context.fillRect(0, 0, size, size);
    speckle(
        context,
        size,
        ['#4e6038', '#647851', '#6d7d54', '#42522f'],
        3200,
        3,
    );

    const texture = finishTexture(canvas);
    texture.wrapS = RepeatWrapping;
    texture.wrapT = RepeatWrapping;

    return texture;
}

/** Painted helipad: dark pad, white ring, bold H. */
export function createHelipadTexture(): CanvasTexture {
    const size = 512;
    const [canvas, context] = createCanvas(size);
    const center = size / 2;

    context.fillStyle = '#31373e';
    context.beginPath();
    context.arc(center, center, center - 4, 0, Math.PI * 2);
    context.fill();

    context.strokeStyle = '#e8eaed';
    context.lineWidth = 22;
    context.beginPath();
    context.arc(center, center, center - 40, 0, Math.PI * 2);
    context.stroke();

    context.fillStyle = '#e8eaed';
    context.font = 'bold 240px system-ui, sans-serif';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText('H', center, center + 12);

    return finishTexture(canvas);
}

/** Concentric landing target for the goal zone. */
export function createTargetPadTexture(): CanvasTexture {
    const size = 512;
    const [canvas, context] = createCanvas(size);
    const center = size / 2;

    const rings: [number, string][] = [
        [1, '#3b4148'],
        [0.82, '#f0f2f4'],
        [0.62, '#f97316'],
        [0.42, '#f0f2f4'],
        [0.22, '#f97316'],
    ];

    rings.forEach(([ratio, color]) => {
        context.fillStyle = color;
        context.beginPath();
        context.arc(center, center, (center - 4) * ratio, 0, Math.PI * 2);
        context.fill();
    });

    return finishTexture(canvas);
}

/** Diagonal orange/white hazard stripes for tall pylon obstacles. */
export function createHazardTexture(): CanvasTexture {
    const size = 128;
    const [canvas, context] = createCanvas(size);

    context.fillStyle = '#e2762d';
    context.fillRect(0, 0, size, size);

    context.save();
    context.translate(size / 2, size / 2);
    context.rotate(-Math.PI / 4);
    context.fillStyle = '#eceff1';

    const stripe = 24;

    for (let x = -size * 1.5; x < size * 1.5; x += stripe * 2) {
        context.fillRect(x, -size, stripe, size * 2);
    }

    context.restore();

    const texture = finishTexture(canvas);
    texture.wrapS = RepeatWrapping;
    texture.wrapT = RepeatWrapping;

    return texture;
}
