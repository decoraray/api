<?php

namespace App\Services\Production\Drafts;

/**
 * A parametric shape («الگوی طرح») that turns overall dimensions and a few counts into a cutting list and hardware.
 * Dimensions in params are whole cm; parts are in mm in the cutting list's validated shape.
 */
interface DesignTemplate
{
    /** Hardware roles a template can ask for, matched to stock items in the draft settings. */
    public const HARDWARE = ['hinge', 'slide', 'handle', 'leg'];

    public function key(): string;

    public function name(): string;

    public function axisRule(): AxisRule;

    /** @return array<int, array{key: string, label: string, default: int, min: int, max: int}> */
    public function params(): array;

    /** @return array<string, int> every parameter at its default */
    public function defaults(): array;

    /** @return array<string, int> every parameter present and inside its range */
    public function clamp(array $params): array;

    /** Full parameters for a reading: its dimensions, the defaults for the rest, and counts that follow from size. */
    public function suggest(DimensionReading $reading): array;

    /** @return array<int, array> parts for one unit, each passing CuttingListService::partRules() */
    public function parts(array $params, DraftMaterials $materials): array;

    /** @return array<string, int> quantity per unit for each hardware role used */
    public function hardware(array $params): array;
}
