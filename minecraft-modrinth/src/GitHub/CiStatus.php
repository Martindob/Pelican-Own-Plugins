<?php

namespace Boy132\MinecraftModrinth\GitHub;

/** CI state of the commit a GitHub repository source currently points at. */
enum CiStatus: string
{
    case Green = 'green';
    case Pending = 'pending';
    case Failed = 'failed';
    case Unverified = 'unverified';

    public function getLabel(): string
    {
        return trans('minecraft-modrinth::strings.github.ci.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Green => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Unverified => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Green => 'tabler-circle-check',
            self::Pending => 'tabler-clock',
            self::Failed => 'tabler-circle-x',
            self::Unverified => 'tabler-help-circle',
        };
    }

    /** How long (minutes) this result may be cached: settled results longer than moving ones. */
    public function cacheMinutes(): int
    {
        return match ($this) {
            self::Green, self::Failed => 10,
            self::Pending, self::Unverified => 1,
        };
    }
}
