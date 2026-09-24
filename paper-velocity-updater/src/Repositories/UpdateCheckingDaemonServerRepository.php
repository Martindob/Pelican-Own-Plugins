<?php

namespace Martindob\PaperVelocityUpdater\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

/**
 * Decorates the panel's own DaemonServerRepository so that a "start"/"restart"
 * power action runs the Paper/Velocity update check first, before the signal
 * is forwarded to Wings. This covers both manual power actions (console,
 * client API) and the "Power Action" scheduled task, since both resolve this
 * class out of the container.
 */
class UpdateCheckingDaemonServerRepository extends DaemonServerRepository
{
    private const UPDATE_ON_SIGNALS = ['start', 'restart'];

    public function __construct(private readonly PaperVelocityUpdateService $updateService) {}

    /**
     * @throws ConnectionException
     */
    public function power(string $action): Response
    {
        if (isset($this->server) && in_array($action, self::UPDATE_ON_SIGNALS, true)) {
            $this->updateService->maybeUpdate($this->server);
        }

        return parent::power($action);
    }
}
