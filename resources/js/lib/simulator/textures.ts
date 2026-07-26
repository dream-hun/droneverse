import { CanvasTexture, RepeatWrapping, SRGBColorSpace } from 'three';
import { cachedTexture } from './texture-cache';

/**
 * Procedural textures for the flying field, generated on a canvas at mount
 * time so the simulator ships no image assets. Only called from components
 * rendered inside the R3F <Canvas>, which never runs during SSR.
 *
 * Every exported accessor returns a *shared* texture from
 * {@see cachedTexture}: a field of twenty identical crates paints one canvas,
 * not twenty. Two consequences follow, and both are load-bearing:
 *
 * - Callers must not dispose or mutate what they are given. Anything that
 *   varies per use — `repeat`, above all — is an argument here, so that
 *   different needs resolve to different cache entries instead of callers
 *   fighting over one texture's properties.
 * - Generation is seeded rather than random. Identical inputs have to paint
 *   identical pixels, or sharing one texture between two objects would be
 *   visible.
 */

function createCanvas(
    size: number,
): [HTMLCanvasElement, CanvasRenderingContext2D] {
    return createCanvasOfSize(size, size);
}

function createCanvasOfSize(
    width: number,
    height: number,
): [HTMLCanvasElement, CanvasRenderingContext2D] {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

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

function tiled(texture: CanvasTexture, x: number, y: number): CanvasTexture {
    texture.wrapS = RepeatWrapping;
    texture.wrapT = RepeatWrapping;
    texture.repeat.set(x, y);

    return texture;
}

/** Small deterministic PRNG so a given building always renders the same. */
function mulberry32(seed: number): () => number {
    let state = seed >>> 0;

    return () => {
        state |= 0;
        state = (state + 0x6d2b79f5) | 0;
        let t = Math.imul(state ^ (state >>> 15), 1 | state);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function clampInt(value: number, min: number, max: number): number {
    return Math.max(min, Math.min(max, Math.round(value)));
}

function speckle(
    context: CanvasRenderingContext2D,
    size: number,
    colors: string[],
    count: number,
    maxDot: number,
    rng: () => number,
): void {
    for (let i = 0; i < count; i++) {
        context.fillStyle = colors[i % colors.length];
        context.globalAlpha = 0.03 + rng() * 0.05;
        context.fillRect(
            rng() * size,
            rng() * size,
            1 + rng() * maxDot,
            1 + rng() * maxDot,
        );
    }

    context.globalAlpha = 1;
}

/**
 * Asphalt flying field with a painted 1 m survey grid (bright every 5 m),
 * sky-blue center axes, and a yellow boundary line. The grid doubles as a
 * distance reference for the meters used in drone commands.
 */
function createFieldTexture(
    widthMeters: number,
    depthMeters: number,
): CanvasTexture {
    const pixelsPerMeter = Math.min(
        24,
        2048 / Math.max(widthMeters, depthMeters),
    );
    const [canvas, context] = createCanvasOfSize(
        Math.round(widthMeters * pixelsPerMeter),
        Math.round(depthMeters * pixelsPerMeter),
    );
    const rng = mulberry32(canvas.width * 73856093 + canvas.height);

    context.fillStyle = '#474d55';
    context.fillRect(0, 0, canvas.width, canvas.height);

    for (let i = 0; i < (canvas.width * canvas.height) / 40; i++) {
        context.fillStyle = rng() > 0.5 ? '#ffffff' : '#000000';
        context.globalAlpha = 0.02 + rng() * 0.045;
        const dot = 1 + rng() * 2;
        context.fillRect(rng() * canvas.width, rng() * canvas.height, dot, dot);
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
function createGrassTexture(): CanvasTexture {
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
        mulberry32(0x6a55),
    );

    return finishTexture(canvas);
}

/** Painted helipad: dark pad, white ring, bold H. */
function createHelipadTexture(): CanvasTexture {
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
function createTargetPadTexture(): CanvasTexture {
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

// A few realistic concrete/stone facade tones for building piers.
const FACADE_CONCRETE = ['#8b8f96', '#9a9285', '#7a828d', '#a49d94', '#6f7681'];

/**
 * A glazed high-rise facade: concrete piers and floor spandrels framing a
 * grid of window bays. Each pane is tinted glass with a cool sky-reflection
 * gradient; a scattering of panes read as occupied (blinds) or lit, so no two
 * buildings look identical. Sized in whole bays and floors so windows line up
 * floor-to-floor across every face of the tower.
 */
function createFacadeTexture(
    bays: number,
    floors: number,
    seed: number,
): CanvasTexture {
    const rng = mulberry32(seed);
    const bayPx = Math.min(56, Math.floor(1792 / bays));
    const floorPx = Math.min(60, Math.floor(1792 / floors));
    const [canvas, context] = createCanvasOfSize(
        bays * bayPx,
        floors * floorPx,
    );

    const concrete =
        FACADE_CONCRETE[Math.floor(rng() * FACADE_CONCRETE.length)];
    context.fillStyle = concrete;
    context.fillRect(0, 0, canvas.width, canvas.height);

    // Faint vertical streaking down the concrete piers.
    for (let i = 0; i < canvas.width * 0.6; i++) {
        context.fillStyle = rng() > 0.5 ? '#ffffff' : '#000000';
        context.globalAlpha = 0.015 + rng() * 0.03;
        context.fillRect(rng() * canvas.width, 0, 1, canvas.height);
    }

    context.globalAlpha = 1;

    const pierW = Math.max(3, bayPx * 0.16);
    const spandrelH = Math.max(3, floorPx * 0.22);

    for (let floor = 0; floor < floors; floor++) {
        // Row 0 is the ground floor (canvas bottom): a taller, darker lobby.
        const isLobby = floor === 0;
        const y = canvas.height - (floor + 1) * floorPx;
        const paneY = y + spandrelH / 2;
        const paneH = floorPx - spandrelH;

        for (let bay = 0; bay < bays; bay++) {
            const x = bay * bayPx;
            const paneX = x + pierW / 2;
            const paneW = bayPx - pierW;

            const roll = rng();
            let top: string;
            let bottom: string;

            if (isLobby) {
                top = '#33404d';
                bottom = '#1d262f';
            } else if (roll > 0.88) {
                // Warm interior light.
                top = '#c9a24d';
                bottom = '#8a6f39';
            } else if (roll > 0.62) {
                // Darker occupied pane (blinds / furniture).
                top = '#33414f';
                bottom = '#212c37';
            } else {
                // Cool glass reflecting the sky.
                top = '#8fb4cf';
                bottom = '#41586c';
            }

            const gradient = context.createLinearGradient(
                0,
                paneY,
                0,
                paneY + paneH,
            );
            gradient.addColorStop(0, top);
            gradient.addColorStop(1, bottom);
            context.fillStyle = gradient;
            context.fillRect(paneX, paneY, paneW, paneH);

            // Thin bright mullion frame around the pane.
            context.strokeStyle = 'rgba(20, 24, 28, 0.55)';
            context.lineWidth = 1.5;
            context.strokeRect(paneX + 0.5, paneY + 0.5, paneW - 1, paneH - 1);

            // A subtle diagonal glint on some reflective panes.
            if (!isLobby && roll < 0.62 && rng() > 0.55) {
                context.strokeStyle = 'rgba(255, 255, 255, 0.14)';
                context.lineWidth = 2;
                context.beginPath();
                context.moveTo(paneX + paneW * 0.15, paneY + paneH);
                context.lineTo(paneX + paneW * 0.6, paneY);
                context.stroke();
            }
        }
    }

    return finishTexture(canvas);
}

/** Tar-and-gravel rooftop with a painted border for the roof caps. */
function createRoofTexture(seed: number): CanvasTexture {
    const size = 256;
    const [canvas, context] = createCanvas(size);
    const rng = mulberry32(seed);

    context.fillStyle = '#3b3f45';
    context.fillRect(0, 0, size, size);

    for (let i = 0; i < 2600; i++) {
        const shade = Math.floor(40 + rng() * 90);
        context.fillStyle = `rgb(${shade}, ${shade + 4}, ${shade + 8})`;
        context.globalAlpha = 0.25 + rng() * 0.4;
        const dot = 1 + rng() * 2.5;
        context.fillRect(rng() * size, rng() * size, dot, dot);
    }

    context.globalAlpha = 1;

    // Faint seams between roofing panels.
    context.strokeStyle = 'rgba(20, 22, 26, 0.5)';
    context.lineWidth = 2;

    for (let g = 0; g <= size; g += size / 4) {
        context.beginPath();
        context.moveTo(g, 0);
        context.lineTo(g, size);
        context.moveTo(0, g);
        context.lineTo(size, g);
        context.stroke();
    }

    return finishTexture(canvas);
}

/** Weathered wooden shipping crate: planks, cross-brace, and stencil. */
function createCrateTexture(): CanvasTexture {
    const size = 256;
    const [canvas, context] = createCanvas(size);
    const rng = mulberry32(0xc4a7e);

    context.fillStyle = '#a9752f';
    context.fillRect(0, 0, size, size);

    // Horizontal planks with a darker grain between each.
    const planks = 5;
    const plankH = size / planks;

    for (let i = 0; i < planks; i++) {
        const tone = 150 + Math.round(rng() * 30);
        context.fillStyle = `rgb(${tone + 20}, ${tone - 30}, ${Math.round((tone - 60) * 0.7)})`;
        context.fillRect(0, i * plankH + 2, size, plankH - 4);
    }

    speckle(context, size, ['#7a5320', '#c79355', '#5f4018'], 1800, 3, rng);

    // Corner brackets and a diagonal cross-brace.
    context.strokeStyle = '#5f4a2b';
    context.lineWidth = 10;
    context.strokeRect(6, 6, size - 12, size - 12);
    context.lineWidth = 8;
    context.beginPath();
    context.moveTo(10, 10);
    context.lineTo(size - 10, size - 10);
    context.moveTo(size - 10, 10);
    context.lineTo(10, size - 10);
    context.stroke();

    // "FRAGILE" stencil hint.
    context.fillStyle = 'rgba(40, 30, 18, 0.55)';
    context.font = 'bold 34px system-ui, sans-serif';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.save();
    context.translate(size / 2, size / 2);
    context.fillText('CARGO', 0, 0);
    context.restore();

    return finishTexture(canvas);
}

/** Pre-cast concrete panel with form seams, for walls and low barriers. */
function createConcreteTexture(): CanvasTexture {
    const size = 256;
    const [canvas, context] = createCanvas(size);

    context.fillStyle = '#9ca1a6';
    context.fillRect(0, 0, size, size);
    speckle(
        context,
        size,
        ['#b4b8bc', '#82878c', '#6f7377'],
        2400,
        3,
        mulberry32(0xc0c2e7e),
    );

    // Form-panel seams and tie-rod holes.
    context.strokeStyle = 'rgba(60, 64, 68, 0.45)';
    context.lineWidth = 2;

    for (let g = 0; g <= size; g += size / 4) {
        context.beginPath();
        context.moveTo(g, 0);
        context.lineTo(g, size);
        context.moveTo(0, g);
        context.lineTo(size, g);
        context.stroke();
    }

    context.fillStyle = 'rgba(40, 44, 48, 0.5)';

    for (let x = size / 8; x < size; x += size / 4) {
        for (let y = size / 8; y < size; y += size / 4) {
            context.beginPath();
            context.arc(x, y, 2.2, 0, Math.PI * 2);
            context.fill();
        }
    }

    return finishTexture(canvas);
}

/** Illuminated "DRONE WASH" board mounted over the wash tunnel mouths. */
function createCarwashSignTexture(): CanvasTexture {
    const width = 512;
    const height = 128;
    const [canvas, context] = createCanvasOfSize(width, height);

    const backdrop = context.createLinearGradient(0, 0, 0, height);
    backdrop.addColorStop(0, '#0b3050');
    backdrop.addColorStop(1, '#082238');
    context.fillStyle = backdrop;
    context.fillRect(0, 0, width, height);

    context.strokeStyle = '#38bdf8';
    context.lineWidth = 6;
    context.strokeRect(5, 5, width - 10, height - 10);

    // A few soap bubbles drifting behind the lettering.
    const bubbles: [number, number, number][] = [
        [52, 38, 15],
        [80, 84, 9],
        [438, 42, 12],
        [462, 88, 17],
        [415, 92, 7],
    ];

    for (const [x, y, radius] of bubbles) {
        context.beginPath();
        context.arc(x, y, radius, 0, Math.PI * 2);
        context.strokeStyle = 'rgba(125, 211, 252, 0.7)';
        context.lineWidth = 3;
        context.stroke();
    }

    context.font = 'bold 58px system-ui, sans-serif';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillStyle = '#e0f2fe';
    context.shadowColor = '#38bdf8';
    context.shadowBlur = 18;
    context.fillText('DRONE WASH', width / 2, height / 2 + 2);
    context.shadowBlur = 0;

    return finishTexture(canvas);
}

/** Diagonal orange/white hazard stripes for tall pylon obstacles. */
function createHazardTexture(): CanvasTexture {
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

    return finishTexture(canvas);
}

// --- Shared accessors -------------------------------------------------
// Each keys the cache on exactly the inputs that change the pixels, so
// callers asking for the same thing are handed the same texture.

export function fieldTexture(
    widthMeters: number,
    depthMeters: number,
): CanvasTexture {
    return cachedTexture(`field:${widthMeters}x${depthMeters}`, () =>
        createFieldTexture(widthMeters, depthMeters),
    );
}

export function grassTexture(repeat: number): CanvasTexture {
    return cachedTexture(`grass:${repeat}`, () =>
        tiled(createGrassTexture(), repeat, repeat),
    );
}

export function helipadTexture(): CanvasTexture {
    return cachedTexture('helipad', createHelipadTexture);
}

export function targetPadTexture(): CanvasTexture {
    return cachedTexture('target-pad', createTargetPadTexture);
}

/**
 * A tower facade sized to a wall in meters.
 *
 * The pixels depend only on the whole number of bays and floors the wall
 * resolves to, so two towers of slightly different size — but the same bay
 * and floor count — legitimately share one texture.
 */
export function facadeTexture(
    widthMeters: number,
    heightMeters: number,
    seed: number,
): CanvasTexture {
    const bays = clampInt(widthMeters / 2.6, 2, 14);
    const floors = clampInt(heightMeters / 3.2, 3, 40);

    return cachedTexture(`facade:${bays}:${floors}:${seed}`, () =>
        tiled(createFacadeTexture(bays, floors, seed), 1, 1),
    );
}

export function roofTexture(seed: number): CanvasTexture {
    return cachedTexture(`roof:${seed}`, () => createRoofTexture(seed));
}

export function crateTexture(): CanvasTexture {
    return cachedTexture('crate', () => tiled(createCrateTexture(), 1, 1));
}

export function concreteTexture(
    repeatX: number,
    repeatY: number,
): CanvasTexture {
    return cachedTexture(`concrete:${repeatX}:${repeatY}`, () =>
        tiled(createConcreteTexture(), repeatX, repeatY),
    );
}

export function hazardTexture(repeatX: number, repeatY: number): CanvasTexture {
    return cachedTexture(`hazard:${repeatX}:${repeatY}`, () =>
        tiled(createHazardTexture(), repeatX, repeatY),
    );
}

export function carwashSignTexture(): CanvasTexture {
    return cachedTexture('carwash-sign', createCarwashSignTexture);
}
