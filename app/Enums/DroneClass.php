<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of machine an airframe is, as a pilot would describe it.
 *
 * A class is a shorthand for the trade the drone makes, not a capability the
 * catalogue gates on: every drone in the fleet is flyable by any pilot the
 * drone configuration feature is open to. It exists so the picker can group
 * and badge a fleet that will grow, and so the one sentence a pilot reads
 * before committing to an airframe comes from the same place as the numbers.
 */
enum DroneClass: string
{
    case Trainer = 'trainer';
    case Inspection = 'inspection';
    case Racing = 'racing';
    case Cargo = 'cargo';
    case Endurance = 'endurance';

    public function label(): string
    {
        return match ($this) {
            self::Trainer => 'Trainer',
            self::Inspection => 'Inspection',
            self::Racing => 'Racing',
            self::Cargo => 'Heavy Lift',
            self::Endurance => 'Endurance',
        };
    }

    /**
     * The trade this class makes, in the words the picker shows.
     */
    public function tagline(): string
    {
        return match ($this) {
            self::Trainer => 'Slow, steady, and hard to fly badly.',
            self::Inspection => 'The all-rounder every mission is authored against.',
            self::Racing => 'Everything traded for speed, including your margin for error.',
            self::Cargo => 'Deliberate, heavy, and wide enough to notice.',
            self::Endurance => 'Modest speed bought back as time in the air.',
        };
    }
}
